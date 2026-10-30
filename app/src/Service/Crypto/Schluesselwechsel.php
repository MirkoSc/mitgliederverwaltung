<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Config\ConfigException;
use App\Dienste;
use App\Domain\AuditAktion;
use App\Installer\ConfigWriter;
use App\Repository\SchluesselwechselRepository;
use App\Service\Audit\AuditLog;
use App\Service\Backup\BackupErstellung;
use App\Service\Backup\BackupLaeuftBereits;
use App\Service\Mail\MailException;
use App\Service\Mail\ServerKeyGeheimnisSchutz;

/**
 * Changes the server key after config.php may have fallen into the wrong
 * hands (docs/spec/02-sicherheit.md section 2, issue #80). Only the small
 * values under the key are wrapped anew - the file keys of the blobs
 * (`file_blob.key_enc`), the authenticator secrets and the SMTP password -
 * never the files themselves.
 *
 * config.php is the one record of truth, and every moment of the chain is
 * safe to break off:
 *
 *  1. starten(): a new key becomes `server_key`, the old one moves to
 *     `server_key_alt` - in one atomic rewrite of config.php. From the next
 *     request on everything encrypts under the new key and still decrypts
 *     under either (ServerCrypto, ServerKeyGeheimnisSchutz).
 *  2. schritt(), again and again: one portion of values per request, each
 *     decrypted and encrypted under the new key with a compare-and-swap.
 *     A value already under the new key is skipped, so a repeated or
 *     doubled step changes nothing.
 *  3. A request that read config.php before step 1 may still write a value
 *     under the old key. So the chain waits GNADENFRIST seconds after
 *     step 1 and then walks everything once more; only a pass that began
 *     after that time ends the chain.
 *  4. Abschluss: config.php is rewritten without `server_key_alt`. From
 *     then on the old key opens nothing here any more.
 *
 * Whatever breaks - the browser, the request, the server between two
 * writes - the next step finds config.php either with both keys (and goes
 * on) or with only the new one (and records the end). The keys never leave
 * config.php: the state file `schluesselwechsel.json` under shared/var holds
 * fingerprints, positions and counts only.
 *
 * The second-factor values stored as HMAC under the key (backup codes,
 * remembered devices, open mail codes) cannot be wrapped anew - only their
 * hash is stored. They stop matching with step 1 and are removed then.
 */
final readonly class Schluesselwechsel
{
    /**
     * Seconds after the new key went into config.php before the final pass
     * may begin: longer than any request that read the file before
     * (max_execution_time 30 s) plus an opcode cache that keeps serving the
     * old file for a while.
     */
    public const int GNADENFRIST = 120;

    /** Values per database query. */
    public const int PORTION = 500;

    /** Seconds of work per step - well inside max_execution_time = 30. */
    public const float PORTION_SEKUNDEN = 3.0;

    public const string PHASE_UMSCHLUESSELN = 'umschluesseln';
    public const string PHASE_WARTEN = 'warten';
    public const string PHASE_FERTIG = 'fertig';

    /** What the chain walks, in this order. */
    public const array BEREICHE = ['dateien', 'app', 'smtp'];

    private const string STATUS = 'schluesselwechsel.json';
    private const string SPERRE = 'schluesselwechsel.lock';
    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param string $configDatei shared/config.php
     * @param string $statusDir shared/var
     * @param \Closure(): bool $backupLaeuft whether a backup is being made
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     * @param (\Closure(\Closure(): void): void)|null $ohneBackup runs the
     *        rewrite of config.php so that no backup can start meanwhile
     *        (BackupErstellung::ausschliesslich()); throws BackupLaeuftBereits
     *        when one runs. Null: only $backupLaeuft is asked.
     */
    public function __construct(
        private string $configDatei,
        private string $statusDir,
        private SchluesselwechselRepository $repo,
        private AuditLog $audit,
        private \Closure $backupLaeuft,
        private ?\Closure $clock = null,
        private int $gnadenfrist = self::GNADENFRIST,
        private int $portion = self::PORTION,
        private float $portionSekunden = self::PORTION_SEKUNDEN,
        private ?\Closure $ohneBackup = null,
    ) {
    }

    public static function aus(Dienste $d): self
    {
        return $d->dienst(self::class, static fn(Dienste $d): self => new self(
            configDatei: $d->paths->configFile(),
            statusDir: $d->paths->varDir(),
            repo: new SchluesselwechselRepository($d->pdo()),
            audit: $d->audit(),
            backupLaeuft: static fn(): bool => BackupErstellung::aus($d)->laeuft(),
            ohneBackup: static function (\Closure $arbeit) use ($d): void {
                BackupErstellung::aus($d)->ausschliesslich($arbeit);
            },
        ));
    }

    /**
     * The state of the current or last change, null when there was none.
     *
     * @return array<string, mixed>|null
     */
    public function stand(): ?array
    {
        $pfad = $this->statusDir . '/' . self::STATUS;
        if (!is_file($pfad)) {
            return null;
        }
        $daten = json_decode((string) file_get_contents($pfad), true);

        return is_array($daten) && isset($daten['phase'], $daten['neu']) ? $daten : null;
    }

    /**
     * Whether config.php holds a previous key - a change runs or was
     * interrupted and has to be continued.
     */
    public function laeuft(): bool
    {
        try {
            return ConfigWriter::schluesselLesen($this->configDatei)[1] !== null;
        } catch (ConfigException) {
            return false;
        }
    }

    /**
     * Whether there is something for schritt() to do: a change runs, or its
     * last write went through and only the record of it is missing.
     */
    public function offen(): bool
    {
        $stand = $this->stand();

        return $this->laeuft() || ($stand !== null && $stand['phase'] !== self::PHASE_FERTIG);
    }

    /**
     * The new key once more (base64) - after a finished change, until
     * somebody confirms it is printed (ausgedruckt()). The answer that
     * finished the change may have gone to another admin's browser, or
     * been lost on the way.
     */
    public function schluesselAnzeigen(): ?string
    {
        $stand = $this->stand();
        if ($stand === null || $stand['phase'] !== self::PHASE_FERTIG || ($stand['ausgedruckt'] ?? false) === true) {
            return null;
        }
        [$schluessel, $alt] = $this->schluessel();

        return $alt === null && hash_equals((string) $stand['neu'], self::fingerabdruck($schluessel)) ? base64_encode($schluessel) : null;
    }

    /** Somebody has printed the new key: it is not shown again. */
    public function ausgedruckt(): void
    {
        $this->gesperrt(function (): void {
            $stand = $this->stand();
            if ($stand !== null && $stand['phase'] === self::PHASE_FERTIG) {
                $this->speichern([...$stand, 'ausgedruckt' => true]);
            }
        });
    }

    /**
     * Seconds until the final pass may begin, 0 when it may.
     *
     * @param array<string, mixed> $stand
     */
    public function wartezeit(array $stand): int
    {
        return max(0, (int) $stand['seit'] + $this->gnadenfrist - $this->jetzt()->getTimestamp());
    }

    /**
     * Step 1: a new key into config.php, the old one beside it. A change
     * that was interrupted is continued instead - never a second new key
     * while the first is not through.
     *
     * @throws SchluesselwechselNichtMoeglich
     * @throws SchluesselwechselLaeuftBereits
     */
    public function starten(?int $userId): void
    {
        $this->gesperrt(function () use ($userId): void {
            [$schluessel, $alt] = $this->schluessel();
            $stand = $this->stand();
            if ($alt === null && $stand !== null && $stand['phase'] !== self::PHASE_FERTIG
                && hash_equals((string) $stand['neu'], self::fingerabdruck($schluessel))) {
                // The last change went through without its record - finish
                // that one instead of starting another.
                $this->vermerkeAbschluss($stand, $schluessel);

                return;
            }

            if ($alt !== null) {
                $stand = $this->stand();
                if ($stand === null || !$this->gehoertZu($stand, $schluessel, $alt)) {
                    $this->speichern($this->neuerStand($schluessel, $alt, $userId));
                }

                return;
            }

            $neu = random_bytes(ServerCrypto::KEY_BYTES);
            try {
                $this->ohneBackupLaufen(function () use ($neu, $schluessel): void {
                    ConfigWriter::schluesselSchreiben($this->configDatei, $neu, $schluessel);
                });
            } catch (BackupLaeuftBereits) {
                throw new SchluesselwechselNichtMoeglich('Gerade wird ein Backup erstellt. Bitte warten Sie, bis es fertig ist.');
            } catch (\RuntimeException $e) {
                // The old file stays in place: nothing has changed yet.
                throw new SchluesselwechselNichtMoeglich($e->getMessage(), previous: $e);
            }
            $this->speichern($this->neuerStand($neu, $schluessel, $userId));
            $this->audit->protokollieren(AuditAktion::SchluesselwechselBegonnen, $userId, 'server_schluessel');
        });
    }

    /**
     * One step of the chain. Does nothing when no change runs.
     *
     * @return string|null the new key (base64, as config.php and the printout
     *         hold it) - only in the answer of the one step that finished
     *         the change, never stored anywhere else
     *
     * @throws SchluesselwechselLaeuftBereits
     */
    public function schritt(): ?string
    {
        return $this->gesperrt(function (): ?string {
            [$schluessel, $alt] = $this->schluessel();
            $stand = $this->stand();
            $offen = $stand !== null && $stand['phase'] !== self::PHASE_FERTIG;

            if ($alt === null) {
                // Only the new key left: the last write went through, the
                // record of it may not have. Anything else is stale.
                if ($offen && hash_equals((string) $stand['neu'], self::fingerabdruck($schluessel))) {
                    return $this->vermerkeAbschluss($stand, $schluessel);
                }
                if ($offen) {
                    @unlink($this->statusDir . '/' . self::STATUS);
                }

                return null;
            }
            if (!$offen || !$this->gehoertZu($stand, $schluessel, $alt)) {
                // Both keys in config.php but no record of them (lost, or
                // the chain was started anew): from the beginning, with the
                // waiting time counted from now.
                $stand = $this->neuerStand($schluessel, $alt, $stand['benutzer'] ?? null);
            }

            return $this->weiter($stand, $schluessel, $alt);
        });
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function weiter(array $stand, string $schluessel, string $alt): ?string
    {
        $jetzt = $this->jetzt()->getTimestamp();

        if ($stand['hashes'] === null) {
            // HMACs under the old key stopped matching the moment the new
            // key went live; gone, so no page shows codes that cannot work.
            $stand['hashes'] = $this->repo->zweiterFaktorHashesLoeschen($this->jetzt()->setTimestamp((int) $stand['seit']));
        }

        if ($stand['phase'] === self::PHASE_WARTEN) {
            if ($jetzt < (int) $stand['seit'] + $this->gnadenfrist
                || (($stand['wartet_auf_backup'] ?? false) === true && ($this->backupLaeuft)())) {
                $this->speichern([...$stand, 'aktualisiert' => $jetzt]);

                return null;
            }
            $stand = $this->neuerDurchgang($stand);
        }

        $stand = $this->portion($stand, new ServerCrypto($schluessel, $alt), new ServerKeyGeheimnisSchutz($schluessel, $alt));

        if ($stand['bereich'] === null) {
            $endgueltig = (int) $stand['durchgang_beginn'] >= (int) $stand['seit'] + $this->gnadenfrist;
            $backup = ($this->backupLaeuft)();
            if ($endgueltig && !$backup) {
                return $this->abschliessen($stand, $schluessel);
            }
            // Another pass right away once the waiting time is over;
            // otherwise wait - for the time, or for a backup to finish (a
            // backup begun under the old key must not outlive it).
            $stand = !$endgueltig && $this->jetzt()->getTimestamp() >= (int) $stand['seit'] + $this->gnadenfrist
                ? $this->neuerDurchgang($stand)
                : [...$stand, 'phase' => self::PHASE_WARTEN, 'wartet_auf_backup' => $endgueltig && $backup];
        }
        $this->speichern([...$stand, 'aktualisiert' => $this->jetzt()->getTimestamp()]);

        return null;
    }

    /**
     * Wraps the next values anew, until the portion or the time is used up.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function portion(array $stand, ServerCrypto $crypto, ServerKeyGeheimnisSchutz $schutz): array
    {
        $beginn = microtime(true);
        do {
            switch ($stand['bereich']) {
                case 'dateien':
                case 'app':
                    $dateien = $stand['bereich'] === 'dateien';
                    $werte = $dateien
                        ? $this->repo->dateischluessel((int) $stand['position'], $this->portion)
                        : $this->repo->appGeheimnisse((int) $stand['position'], $this->portion);
                    foreach ($werte as $id => $wert) {
                        $stand = $this->umschluesseln($stand, $wert, $crypto, $dateien
                            ? fn(string $neu): bool => $this->repo->dateischluesselErsetzen($id, $wert, $neu)
                            : fn(string $neu): bool => $this->repo->appGeheimnisErsetzen($id, $wert, $neu));
                        $stand['position'] = $id;
                    }
                    if (count($werte) < $this->portion) {
                        $stand = [...$stand, 'bereich' => $dateien ? 'app' : 'smtp', 'position' => 0];
                    }
                    break;
                case 'smtp':
                    $wert = $this->repo->smtpPasswort();
                    if ($wert !== null && !$schutz->mitAktuellemSchluessel($wert)) {
                        try {
                            $neu = $schutz->schuetzen($schutz->oeffnen($wert));
                            if ($this->repo->smtpPasswortErsetzen($wert, $neu)) {
                                $stand['umgeschluesselt']['smtp']++;
                            }
                        } catch (MailException) {
                            $stand['unlesbar']++;
                        }
                    }
                    $stand['erledigt']++;
                    $stand['bereich'] = null;
                    break;
                default:
                    $stand['bereich'] = null;
            }
        } while ($stand['bereich'] !== null && microtime(true) - $beginn < $this->portionSekunden);

        return $stand;
    }

    /**
     * One value: left alone when the new key opens it, otherwise decrypted
     * (under the old key) and stored under the new one. A value neither key
     * opens was unreadable before already and is only counted.
     *
     * @param array<string, mixed> $stand
     * @param \Closure(string): bool $ersetzen
     * @return array<string, mixed>
     */
    private function umschluesseln(array $stand, string $wert, ServerCrypto $crypto, \Closure $ersetzen): array
    {
        $stand['erledigt']++;
        if ($crypto->mitAktuellemSchluessel($wert)) {
            return $stand;
        }
        try {
            $klartext = $crypto->decrypt($wert);
        } catch (CryptoException) {
            $stand['unlesbar']++;

            return $stand;
        }
        // A false compare-and-swap means the row changed or went away
        // meanwhile; the next pass looks at it again.
        if ($ersetzen($crypto->encrypt($klartext))) {
            $stand['umgeschluesselt'][$stand['bereich']]++;
        }

        return $stand;
    }

    /**
     * Step 4: config.php without the old key.
     *
     * @param array<string, mixed> $stand
     */
    private function abschliessen(array $stand, string $schluessel): string
    {
        // A request still on the old config.php may have stored a code or
        // remembered a device under the old key during the waiting time.
        // Removed too - a code that is listed but cannot work is worse than
        // one that has to be made again.
        foreach ($this->repo->zweiterFaktorHashesLoeschen($this->jetzt()->setTimestamp((int) $stand['durchgang_beginn'])) as $art => $anzahl) {
            $stand['hashes'][$art] = (int) ($stand['hashes'][$art] ?? 0) + $anzahl;
        }
        // Saved first: should the rewrite fail, the next step repeats the
        // final pass instead of finishing on an older one.
        $this->speichern([...$stand, 'aktualisiert' => $this->jetzt()->getTimestamp()]);
        ConfigWriter::schluesselSchreiben($this->configDatei, $schluessel, null);

        return $this->vermerkeAbschluss($stand, $schluessel);
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function vermerkeAbschluss(array $stand, string $schluessel): string
    {
        $jetzt = $this->jetzt();
        $this->audit->protokollieren(
            AuditAktion::SchluesselGewechselt,
            is_int($stand['benutzer'] ?? null) ? $stand['benutzer'] : null,
            'server_schluessel',
            details: [
                'dateien' => (int) $stand['umgeschluesselt']['dateien'],
                'app' => (int) $stand['umgeschluesselt']['app'],
                'smtp' => (int) $stand['umgeschluesselt']['smtp'],
                'unlesbar' => (int) $stand['unlesbar'],
            ],
        );
        $this->speichern([
            ...$stand,
            'phase' => self::PHASE_FERTIG,
            'bereich' => null,
            'beendet' => $jetzt->format('Y-m-d H:i:s'),
            'aktualisiert' => $jetzt->getTimestamp(),
        ]);

        return base64_encode($schluessel);
    }

    /**
     * @param \Closure(): void $arbeit
     *
     * @throws BackupLaeuftBereits
     */
    private function ohneBackupLaufen(\Closure $arbeit): void
    {
        if ($this->ohneBackup !== null) {
            ($this->ohneBackup)($arbeit);

            return;
        }
        if (($this->backupLaeuft)()) {
            throw new BackupLaeuftBereits('Es wird gerade ein Backup erstellt.');
        }
        $arbeit();
    }

    /**
     * @return array<string, mixed>
     */
    private function neuerStand(string $schluessel, string $alt, ?int $userId): array
    {
        $jetzt = $this->jetzt();

        return $this->neuerDurchgang([
            'phase' => self::PHASE_UMSCHLUESSELN,
            'neu' => self::fingerabdruck($schluessel),
            'alt' => self::fingerabdruck($alt),
            'benutzer' => $userId,
            'begonnen' => $jetzt->format('Y-m-d H:i:s'),
            'beendet' => null,
            // since when config.php holds the new key - the waiting time
            // (GNADENFRIST) counts from here
            'seit' => $jetzt->getTimestamp(),
            'durchgang' => 0,
            'umgeschluesselt' => ['dateien' => 0, 'app' => 0, 'smtp' => 0],
            'hashes' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private function neuerDurchgang(array $stand): array
    {
        return [
            ...$stand,
            'phase' => self::PHASE_UMSCHLUESSELN,
            'wartet_auf_backup' => false,
            'durchgang' => (int) $stand['durchgang'] + 1,
            'durchgang_beginn' => $this->jetzt()->getTimestamp(),
            'bereich' => self::BEREICHE[0],
            'position' => 0,
            'erledigt' => 0,
            'gesamt' => $this->repo->anzahlDateien() + $this->repo->anzahlAppGeheimnisse() + 1,
            'unlesbar' => 0,
            'aktualisiert' => $this->jetzt()->getTimestamp(),
        ];
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function gehoertZu(array $stand, string $schluessel, string $alt): bool
    {
        return hash_equals((string) $stand['neu'], self::fingerabdruck($schluessel))
            && hash_equals((string) ($stand['alt'] ?? ''), self::fingerabdruck($alt));
    }

    /**
     * Tells two keys apart in the state file without revealing anything of
     * them: 64 bits of a hash of 256 random bits.
     */
    private static function fingerabdruck(#[\SensitiveParameter] string $schluessel): string
    {
        return substr(hash('sha256', 'mv-schluesselwechsel|' . $schluessel), 0, 16);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function schluessel(): array
    {
        try {
            return ConfigWriter::schluesselLesen($this->configDatei);
        } catch (ConfigException $e) {
            throw new SchluesselwechselNichtMoeglich($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $stand
     */
    private function speichern(array $stand): void
    {
        $pfad = $this->statusDir . '/' . self::STATUS;
        $tmp = $pfad . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($stand, self::JSON)) === false || !rename($tmp, $pfad)) {
            @unlink($tmp);
            throw new \RuntimeException('Der Stand des Schlüsselwechsels kann nicht gespeichert werden.');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $arbeit
     * @return T
     */
    private function gesperrt(\Closure $arbeit): mixed
    {
        if (!is_dir($this->statusDir) && !@mkdir($this->statusDir, 0775, true) && !is_dir($this->statusDir)) {
            throw new \RuntimeException('Das Verzeichnis shared/var kann nicht angelegt werden.');
        }
        $sperre = fopen($this->statusDir . '/' . self::SPERRE, 'c');
        if ($sperre === false) {
            throw new \RuntimeException('Das Verzeichnis shared/var ist nicht beschreibbar.');
        }
        try {
            if (!flock($sperre, LOCK_EX | LOCK_NB)) {
                throw new SchluesselwechselLaeuftBereits('Gerade läuft ein Schritt des Schlüsselwechsels.');
            }

            return $arbeit();
        } finally {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
    }

    private function jetzt(): \DateTimeImmutable
    {
        return $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();
    }
}
