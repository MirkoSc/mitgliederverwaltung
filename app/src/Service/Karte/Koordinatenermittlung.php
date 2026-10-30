<?php

declare(strict_types=1);

namespace App\Service\Karte;

use App\Dienste;
use App\Repository\GeoAnschriftRepository;
use App\Repository\KartenRepository;
use App\Repository\SettingRepository;
use App\Support\FileLogger;

/**
 * Looks up the coordinates of the members' addresses, a few per call
 * (issue #91): from the cron (App\Service\Cron\KoordinatenTask) and from the
 * admin's button "Jetzt Koordinaten ermitteln" (a short step chain).
 *
 * The rules of the geocoder's usage policy and of this application:
 *   - at most one request per second, across requests: the time of the last
 *     one is a setting, and a database lock keeps the cron and the button
 *     from asking at the same time;
 *   - few addresses per call and a time budget well below the time limit
 *     (CLAUDE.md section 1);
 *   - "not found" is remembered and not asked again until the address
 *     changes (a changed address has another hash);
 *   - a failure of the service is no crash: it is logged without the
 *     address, nothing is stored, and the geocoding rests for a while
 *     before it asks again;
 *   - an address with a search address given by hand (issue #106) is asked
 *     with that one, as typed;
 *   - one address may take two requests (street, then the place alone,
 *     issue #108) - the distance holds between both;
 *   - a point set by hand (issue #108) is never asked and never
 *     overwritten; "Alle neu ermitteln" forgets every automatic result and
 *     keeps search addresses and points set by hand.
 *
 * One address on demand (issue #106: "Jetzt suchen", a search address at a
 * member) goes the same way: the same lock, the same distance, the same
 * rest after a failure - see vonHand().
 */
final readonly class Koordinatenermittlung
{
    /** Seconds between two requests; a little over the policy's one second. */
    public const float ABSTAND_S = 1.1;

    /** How long the geocoding rests after a failure of the service. */
    public const int PAUSE_MINUTEN = 2;

    public const string SETTING_LETZTE_ANFRAGE = 'karte_geocoding_letzte_anfrage';

    public const string SETTING_PAUSE_BIS = 'karte_geocoding_pause_bis';

    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private KartenRepository $mitglieder,
        private GeoAnschriftRepository $koordinaten,
        private SettingRepository $settings,
        private Geocoder $geocoder,
        private Takt $takt,
        private ?FileLogger $logger = null,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            new KartenRepository($d->pdo()),
            new GeoAnschriftRepository($d->pdo()),
            $d->einstellungen(),
            new Geocoder(KartenDienste::http($d), KartenDienste::userAgent($d)),
            $d->dienst(Takt::class, static fn(): Takt => new SystemTakt()),
            $d->logger,
        ));
    }

    /** The counts without asking anybody - for the page. */
    public function stand(\DateTimeImmutable $jetzt): ErmittlungsStand
    {
        return $this->zaehlen($this->mitglieder->anschriften($jetzt), $this->koordinaten->alle(), 0, $this->pauseBis($jetzt));
    }

    /**
     * Asks the geocoder for up to $hoechstens open addresses, stopping early
     * when $zeitbudget seconds are used up.
     */
    public function ermitteln(\DateTimeImmutable $jetzt, int $hoechstens, float $zeitbudget): ErmittlungsStand
    {
        $anschriften = $this->mitglieder->anschriften($jetzt);

        if (!$this->koordinaten->sperren()) {
            $stand = $this->zaehlen($anschriften, $this->koordinaten->alle(), 0, $this->pauseBis($jetzt));

            return $stand->mit(laeuftBereits: true, pauseBis: $stand->pauseBis);
        }

        try {
            // Addresses no current member has any more leave the table.
            $this->koordinaten->entfernenAusser(array_keys($anschriften));
            $bekannt = $this->koordinaten->alle();
            $pauseBis = $this->pauseBis($jetzt);
            $bearbeitet = 0;
            $fehler = '';

            if ($pauseBis === null) {
                $start = $this->takt->jetzt();
                foreach ($anschriften as $hash => $anschrift) {
                    if (($bekannt[$hash]['status'] ?? null) !== null) {
                        continue;
                    }
                    if ($bearbeitet >= $hoechstens || $this->takt->jetzt() - $start >= $zeitbudget) {
                        break;
                    }

                    $ergebnis = $this->fragen($anschrift, $bekannt[$hash]['korrektur'] ?? null);
                    if ($ergebnis->istFehler() || $ergebnis->status === null) {
                        $fehler = $ergebnis->fehler;
                        $pauseBis = $this->pausieren($jetzt, $fehler);
                        break;
                    }

                    $this->koordinaten->speichern($hash, $ergebnis->status, $ergebnis->lat, $ergebnis->lon, $jetzt);
                    $bekannt[$hash] = ['status' => $ergebnis->status, 'lat' => $ergebnis->lat, 'lon' => $ergebnis->lon, 'korrektur' => $bekannt[$hash]['korrektur'] ?? null];
                    $bearbeitet++;
                }
            }

            return $this->zaehlen($anschriften, $bekannt, $bearbeitet, $pauseBis)->mit($bearbeitet, pauseBis: $pauseBis, fehler: $fehler);
        } finally {
            $this->koordinaten->freigeben();
        }
    }

    /** "Jetzt suchen" at a member: asks for this one address now (with its search address, if any). */
    public function jetztSuchen(Anschrift $anschrift, \DateTimeImmutable $jetzt): HandSuche
    {
        return $this->vonHand($anschrift, $jetzt, static function (): void {
        });
    }

    /**
     * Remembers $suche (street, postcode, town) as the search address of
     * $anschrift and asks with it now. The member's address stays as it is.
     */
    public function korrigieren(Anschrift $anschrift, Anschrift $suche, \DateTimeImmutable $jetzt): HandSuche
    {
        return $this->vonHand($anschrift, $jetzt, function () use ($anschrift, $suche, $jetzt): void {
            $this->koordinaten->korrekturSetzen($anschrift->hash(), $suche->strasse, $suche->plz, $suche->ort, $jetzt);
        });
    }

    /** Forgets the search address of $anschrift and asks with the address itself now. */
    public function korrekturEntfernen(Anschrift $anschrift, \DateTimeImmutable $jetzt): HandSuche
    {
        return $this->vonHand($anschrift, $jetzt, function () use ($anschrift): void {
            $this->koordinaten->vergessen($anschrift->hash());
        });
    }

    /**
     * Sets the point of $anschrift by hand (issue #108): no request, no
     * lock - an automatic result never overwrites it (GeoAnschriftRepository::
     * speichern()), not even one the cron is asking for right now.
     *
     * @throws \InvalidArgumentException when the coordinates are no coordinates
     */
    public function punktSetzen(Anschrift $anschrift, float $lat, float $lon, \DateTimeImmutable $jetzt): void
    {
        if (!self::gueltig($lat, $lon)) {
            throw new \InvalidArgumentException('Latitude must be within -90..90 and longitude within -180..180.');
        }
        $this->koordinaten->punktSetzen($anschrift->hash(), $lat, $lon, $jetzt);
    }

    /**
     * Removes the point set by hand and asks automatically again now (with
     * the search address, if there is one) - under the same rules as
     * "Jetzt suchen".
     */
    public function punktEntfernen(Anschrift $anschrift, \DateTimeImmutable $jetzt): HandSuche
    {
        return $this->vonHand($anschrift, $jetzt, function () use ($anschrift): void {
            $this->koordinaten->punktEntfernen($anschrift->hash());
        }, ueberPunktVonHand: true);
    }

    /**
     * "Alle neu ermitteln" (issue #108): forgets every automatic result;
     * search addresses and points set by hand stay. The asking itself is
     * the usual chain (ermitteln()) afterwards.
     *
     * @return int addresses forgotten
     */
    public function alleVergessen(): int
    {
        return $this->koordinaten->automatischeVergessen();
    }

    /** Latitude -90..90, longitude -180..180, both finite. */
    public static function gueltig(float $lat, float $lon): bool
    {
        return is_finite($lat) && is_finite($lon) && abs($lat) <= 90.0 && abs($lon) <= 180.0;
    }

    /**
     * One address on demand: under the lock (held by somebody else: nothing
     * changes, Belegt), $aendern writes the change, then one search with
     * the usual distance - unless the geocoding rests after a failure:
     * then the change stays and the next run asks. A point set by hand
     * blocks every search (PunktVonHand, nothing changed) - except for its
     * own removal ($ueberPunktVonHand).
     *
     * @param \Closure(): void $aendern
     */
    private function vonHand(Anschrift $anschrift, \DateTimeImmutable $jetzt, \Closure $aendern, bool $ueberPunktVonHand = false): HandSuche
    {
        if (!$this->koordinaten->sperren()) {
            return HandSuche::Belegt;
        }

        try {
            $hash = $anschrift->hash();
            if (!$ueberPunktVonHand && ($this->koordinaten->eintrag($hash)['status'] ?? null) === GeoStatus::VonHand) {
                return HandSuche::PunktVonHand;
            }
            $aendern();
            if ($this->pauseBis($jetzt) !== null) {
                return HandSuche::Ruht;
            }

            $ergebnis = $this->fragen($anschrift, $this->koordinaten->eintrag($hash)['korrektur'] ?? null);
            if ($ergebnis->istFehler() || $ergebnis->status === null) {
                $this->pausieren($jetzt, $ergebnis->fehler);

                return HandSuche::Fehler;
            }

            $this->koordinaten->speichern($hash, $ergebnis->status, $ergebnis->lat, $ergebnis->lon, $jetzt);

            return HandSuche::aus($ergebnis->status);
        } finally {
            $this->koordinaten->freigeben();
        }
    }

    /**
     * The search for one address - with the search address given by hand
     * when there is one. Each of its requests (one or two) waits for the
     * distance to the last request and records its own end.
     *
     * @param array{strasse: string, plz: string, ort: string}|null $korrektur
     */
    private function fragen(Anschrift $anschrift, ?array $korrektur): GeoErgebnis
    {
        $jeAnfrage = function (\Closure $anfrage): HttpAntwort {
            $this->abstandHalten();
            try {
                return $anfrage();
            } finally {
                $this->settings->set(self::SETTING_LETZTE_ANFRAGE, $this->takt->jetzt());
            }
        };

        return $korrektur === null
            ? $this->geocoder->suchen($anschrift, jeAnfrage: $jeAnfrage)
            : $this->geocoder->suchen(new Anschrift($korrektur['strasse'], $korrektur['plz'], $korrektur['ort'], $anschrift->land), wortgetreu: true, jeAnfrage: $jeAnfrage);
    }

    /** After a failure of the service: rest for a while; the reason is logged, never the address. */
    private function pausieren(\DateTimeImmutable $jetzt, string $fehler): \DateTimeImmutable
    {
        $pauseBis = $jetzt->modify(sprintf('+%d minutes', self::PAUSE_MINUTEN));
        $this->settings->set(self::SETTING_PAUSE_BIS, $pauseBis->format(self::FORMAT));
        // The reason only - never the address (CLAUDE.md section 4).
        $this->logger?->append(sprintf('Geocoding paused for %d minutes: %s', self::PAUSE_MINUTEN, $fehler));

        return $pauseBis;
    }

    /** Waits until the last request is ABSTAND_S ago - whoever sent it. */
    private function abstandHalten(): void
    {
        $letzte = $this->settings->get(self::SETTING_LETZTE_ANFRAGE);
        if (!is_int($letzte) && !is_float($letzte)) {
            return;
        }
        $warten = (float) $letzte + self::ABSTAND_S - $this->takt->jetzt();
        if ($warten > 0) {
            // A clock that jumped back must not park the request for long.
            $this->takt->warten(min($warten, self::ABSTAND_S));
        }
    }

    private function pauseBis(\DateTimeImmutable $jetzt): ?\DateTimeImmutable
    {
        $wert = $this->settings->text(self::SETTING_PAUSE_BIS);
        if ($wert === '') {
            return null;
        }
        $bis = \DateTimeImmutable::createFromFormat(self::FORMAT, $wert);
        if ($bis === false || $bis <= $jetzt) {
            return null;
        }

        return $bis;
    }

    /**
     * @param array<string, Anschrift> $anschriften
     * @param array<string, array{status: ?GeoStatus, lat: ?float, lon: ?float, korrektur: ?array{strasse: string, plz: string, ort: string}}> $bekannt
     */
    private function zaehlen(array $anschriften, array $bekannt, int $bearbeitet, ?\DateTimeImmutable $pauseBis): ErmittlungsStand
    {
        $anzahl = ['gefunden' => 0, 'ungefaehr' => 0, 'von_hand' => 0, 'nicht_gefunden' => 0];
        foreach (array_keys($anschriften) as $hash) {
            $status = $bekannt[$hash]['status'] ?? null;
            if ($status !== null) {
                $anzahl[$status->value]++;
            }
        }

        return new ErmittlungsStand(
            count($anschriften),
            $anzahl['gefunden'],
            $anzahl['nicht_gefunden'],
            $bearbeitet,
            pauseBis: $pauseBis,
            ungefaehr: $anzahl['ungefaehr'],
            vonHand: $anzahl['von_hand'],
        );
    }
}
