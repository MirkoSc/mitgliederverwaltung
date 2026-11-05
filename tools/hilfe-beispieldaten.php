<?php

/**
 * Development helper, not part of a release (docs/spec/12-hilfe.md section 5):
 * sets up the LOCAL docker environment for the help screenshots - made-up
 * members, families and club data, and two demo accounts (role Vorstand) with
 * the authenticator app as second factor.
 *
 *   docker compose exec -T app php tools/hilfe-beispieldaten.php
 *
 * It deletes ALL members and families first and creates them anew, so a
 * second run gives the same result. It refuses to run unless the
 * configuration has `debug => true` (only docker/shared/config.php has) - a
 * real installation can never be wiped by accident.
 *
 * The login stays untouched (no back door): the second factor is set up
 * through the same service the setup page uses, with a code computed here
 * from the demo secret in tools/hilfe-screenshots/konten.json.
 *
 * Everything in here is invented. Birthdays are relative to today, so the
 * birthday list has entries in the week and in the month, one of them round.
 */

declare(strict_types=1);

namespace App\Tools;

use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Domain\BenutzerStatus;
use App\Domain\Zahlart;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\BenutzerRepository;
use App\Repository\FamilieRepository;
use App\Repository\FunktionRepository;
use App\Repository\MitgliedRepository;
use App\Repository\RateLimitRepository;
use App\Repository\SettingRepository;
use App\Repository\ZweiterFaktorRepository;
use App\Service\Account\PasswordHasher;
use App\Service\Account\Totp;
use App\Service\Account\ZweiterFaktor;
use App\Service\Crypto\ServerCrypto;
use App\Service\RateLimiter;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;

final class HilfeBeispieldaten
{
    /** Where the demo accounts are described (shared with the screenshot script). */
    public const string KONTEN_DATEI = 'tools/hilfe-screenshots/konten.json';

    /** First member number (12 section 5). */
    public const int ERSTE_NUMMER = 1001;

    /** Test bank code and the account numbers built on it: invented. */
    private const string TEST_BLZ = '12345678';

    /**
     * Single members: [first name, last name, sex, turns, in days, years in
     * the club (null = derived), fee type, departments, functions, pays by].
     * "turns" is the age at the next birthday, "in days" the distance to it.
     */
    private const array EINZELNE = [
        ['Peter', 'Vogel', 'm', 41, 0, 25, 'Aktiv', ['Fußball'], ['Wirtschaftshelfer'], 'lastschrift'],
        ['Laura', 'Neumann', 'w', 25, 2, null, 'Jugend/Schüler/Studenten/Azubis', ['Badminton'], [], 'lastschrift'],
        ['Karin', 'Lang', 'w', 60, 4, 12, 'Aktiv', ['Gymnastik'], ['Übungsleiter'], 'lastschrift'],
        ['Sandra', 'Roth', 'w', 30, 5, null, 'Aktiv', ['Badminton', 'Gymnastik'], [], 'lastschrift'],
        ['Ingrid', 'Meier', 'w', 72, 9, 30, 'Rentner aktiv', ['Gymnastik'], [], 'lastschrift'],
        ['Michael', 'Frey', 'm', 34, 12, null, 'Passiv', [], [], 'lastschrift'],
        ['Tanja', 'Becker', 'w', 39, 16, null, 'Aktiv', ['Badminton'], ['Jugendtrainer'], 'lastschrift'],
        ['Josef', 'Bauer', 'm', 65, 20, 20, 'Rentner aktiv', ['Kegeln'], ['Bahndienst'], 'lastschrift'],
        ['Monika', 'Berger', 'w', 49, 55, null, 'Aktiv', ['Gymnastik'], [], 'lastschrift'],
        ['Daniel', 'Fuchs', 'm', 18, 80, 5, 'Jugend/Schüler/Studenten/Azubis', ['Fußball'], [], 'lastschrift'],
        ['Hans', 'Gruber', 'm', 79, 110, 40, 'Rentner passiv', [], [], 'selbst'],
        ['Elke', 'Winter', 'w', 64, 140, null, 'Aktiv', ['Kegeln'], [], 'lastschrift'],
        ['Rudolf', 'Engel', 'm', 56, 170, null, 'Passiv', [], [], 'selbst'],
        ['Werner', 'Hahn', 'm', 82, 200, 50, 'Rentner passiv', ['Kegeln'], [], 'lastschrift'],
        ['Nadine', 'Pohl', 'w', 28, 250, null, 'Mindestbeitrag', ['Badminton'], [], 'lastschrift'],
    ];

    /**
     * Families: name, street, then the members [first name, sex, turns, in
     * days, role, departments].
     */
    private const array FAMILIEN = [
        ['Huber', 'Lindenweg 4', [
            ['Thomas', 'm', 45, 30, 'zahler', ['Fußball']],
            ['Sabine', 'w', 43, 95, 'elternteil', ['Gymnastik']],
            ['Lukas', 'm', 15, 6, 'kind', ['Fußball']],
            ['Emma', 'w', 12, 3, 'kind', ['Badminton']],
        ]],
        ['Brandl', 'Birkenstraße 12', [
            ['Markus', 'm', 48, 120, 'zahler', ['Kegeln']],
            ['Petra', 'w', 46, 25, 'elternteil', []],
            ['Jonas', 'm', 17, 215, 'kind', ['Fußball']],
        ]],
        ['Weiß', 'Am Mühlbach 7', [
            ['Claudia', 'w', 40, 150, 'zahler', ['Gymnastik']],
            ['Mia', 'w', 10, 45, 'kind', ['Badminton']],
            ['Ben', 'm', 8, 300, 'kind', ['Fußball']],
        ]],
        ['Schuster', 'Gartenstraße 21', [
            ['Georg', 'm', 53, 180, 'zahler', ['Kegeln']],
            ['Renate', 'w', 51, 60, 'elternteil', ['Gymnastik']],
            ['Anna', 'w', 20, 270, 'kind', ['Badminton']],
        ]],
        ['Kaiser', 'Eichenallee 3', [
            ['Stefan', 'm', 42, 330, 'zahler', ['Fußball']],
            ['Felix', 'm', 13, 100, 'kind', ['Fußball']],
        ]],
    ];

    private readonly MitgliedRepository $mitglieder;

    /**
     * @throws \RuntimeException without `debug => true`: this helper wipes the members
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Config $config,
        private readonly string $kontenDatei,
    ) {
        if (!$config->debug) {
            throw new \RuntimeException(
                'Refused: the help sample data wipes all members and only runs in the local docker environment (debug => true).',
            );
        }
        $this->mitglieder = new MitgliedRepository($pdo);
    }

    /**
     * @return array{mitglieder: int, familien: int, konten: int}
     */
    public function anlegen(?\DateTimeImmutable $heute = null): array
    {
        $heute = ($heute ?? new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')))->setTime(0, 0);

        $this->mitglieder->alleLoeschen();
        $this->vereinsdaten();
        $anzahl = $this->mitgliederAnlegen($heute);
        $konten = $this->kontenAnlegen();

        return ['mitglieder' => $anzahl['mitglieder'], 'familien' => $anzahl['familien'], 'konten' => $konten];
    }

    /** A German IBAN with correct check digits for an 18-digit BBAN. */
    public static function iban(string $bban): string
    {
        $rest = 0;
        foreach (str_split($bban . '131400') as $ziffer) {
            $rest = ($rest * 10 + (int) $ziffer) % 97;
        }

        return 'DE' . sprintf('%02d', 98 - $rest) . $bban;
    }

    /**
     * Birth date such that the next birthday is $tage days after $heute and
     * makes the member $wird years old. 29 February is avoided (the birthday
     * list moves it, which is not what the pictures are about).
     */
    public static function geburtsdatum(\DateTimeImmutable $heute, int $wird, int $tage): \DateTimeImmutable
    {
        $tag = $heute->modify(sprintf('+%d days', $tage));
        if ($tag->format('m-d') === '02-29') {
            $tag = $tag->modify('+1 day');
        }

        return $tag->modify(sprintf('-%d years', $wird));
    }

    private function vereinsdaten(): void
    {
        $werte = [
            'vereinsname' => 'SV Musterstadt e. V.',
            'kurzname' => 'SV Musterstadt',
            'strasse' => 'Sportplatzweg 1',
            'plz' => '12345',
            'ort' => 'Musterstadt',
            'telefon' => '01234 56789',
            'email' => 'info@example.org',
            'website' => 'https://www.example.org',
            'registergericht' => 'Amtsgericht Musterstadt',
            'registernummer' => 'VR 12345',
            'finanzamt' => 'Finanzamt Musterstadt',
            'steuernummer' => '123/456/78901',
            'freistellungsdatum' => '2025-03-01',
            'kontoinhaber' => 'SV Musterstadt e. V.',
            'iban' => self::iban(self::TEST_BLZ . '0000099999'),
            'bic' => 'MUSTDE99XXX',
            'bankname' => 'Musterbank',
            'glaeubiger_id' => 'DE98ZZZ09999999999',
            'mandatsdatum' => '2020-01-01',
            'verwendungszweck' => 'Mitgliedsbeitrag {jahr} Nr. {mitgliedsnummer}',
        ];
        $verein = new VereinRepository($this->pdo, new SettingRepository($this->pdo));
        $verein->speichern(Vereinsdaten::ausEingabe(static fn(string $feld): string => $werte[$feld] ?? ''));
        $verein->verbaendeErsetzen([
            ['id' => 0, 'name' => 'BLSV', 'vereinsnummer' => '123456', 'bemerkung' => ''],
        ]);
    }

    /** @return array{mitglieder: int, familien: int} */
    private function mitgliederAnlegen(\DateTimeImmutable $heute): array
    {
        $abteilungen = $this->idsNachName((new AbteilungRepository($this->pdo))->alle());
        $funktionen = $this->idsNachName((new FunktionRepository($this->pdo))->alle());
        $beitragsarten = $this->idsNachName((new BeitragsartRepository($this->pdo))->alle());
        $familien = new FamilieRepository($this->pdo);
        $nummer = self::ERSTE_NUMMER;
        $nachweis = ['mitglieder' => 0, 'familien' => 0];

        foreach (self::FAMILIEN as [$familienName, $strasse, $personen]) {
            $familie = $familien->anlegen('Familie ' . $familienName);
            $nachweis['familien']++;
            foreach ($personen as [$vorname, $geschlecht, $wird, $tage, $rolle, $sparten]) {
                $zahler = $rolle === 'zahler';
                $this->speichern(new Mitglied(
                    mitgliedsnummer: $nummer,
                    nachname: $familienName,
                    vorname: $vorname,
                    geschlecht: Geschlecht::from($geschlecht),
                    geburtsdatum: self::geburtsdatum($heute, $wird, $tage),
                    strasse: $strasse,
                    plz: '12345',
                    ort: 'Musterstadt',
                    telefon: $zahler ? self::telefon($nummer) : '',
                    handy: $wird >= 14 ? self::handy($nummer) : '',
                    email: $wird >= 14 ? self::email($vorname, $familienName) : '',
                    eintrittsdatum: self::eintritt($heute, $wird, null),
                    aktiv: true,
                    abteilungIds: $this->ids($abteilungen, $sparten),
                    beitragsartId: $zahler ? ($beitragsarten['Familie'] ?? null) : null,
                    zahlart: Zahlart::Lastschrift,
                    iban: $zahler ? self::iban(self::TEST_BLZ . sprintf('%010d', 1_000_000 + $nummer)) : '',
                    bic: $zahler ? 'MUSTDE99XXX' : '',
                    familieId: $familie->id,
                    familieRolle: FamilienRolle::from($rolle),
                ));
                $nummer++;
                $nachweis['mitglieder']++;
            }
        }

        $strassen = ['Lindenweg', 'Birkenstraße', 'Am Mühlbach', 'Gartenstraße', 'Eichenallee', 'Bergstraße', 'Schulgasse'];
        foreach (self::EINZELNE as $i => [$vorname, $nachname, $geschlecht, $wird, $tage, $seit, $art, $sparten, $aemter, $zahlart]) {
            $lastschrift = $zahlart === 'lastschrift';
            $this->speichern(new Mitglied(
                mitgliedsnummer: $nummer,
                nachname: $nachname,
                vorname: $vorname,
                geschlecht: Geschlecht::from($geschlecht),
                geburtsdatum: self::geburtsdatum($heute, $wird, $tage),
                strasse: $strassen[$i % count($strassen)] . ' ' . (3 + $i * 2),
                plz: '12345',
                ort: 'Musterstadt',
                telefon: self::telefon($nummer),
                handy: self::handy($nummer),
                email: self::email($vorname, $nachname),
                eintrittsdatum: self::eintritt($heute, $wird, $seit),
                aktiv: stripos($art, 'passiv') === false,
                abteilungIds: $this->ids($abteilungen, $sparten),
                beitragsartId: $beitragsarten[$art] ?? null,
                zahlart: Zahlart::from($zahlart),
                iban: $lastschrift ? self::iban(self::TEST_BLZ . sprintf('%010d', 1_000_000 + $nummer)) : '',
                bic: $lastschrift ? 'MUSTDE99XXX' : '',
                funktionIds: $this->ids($funktionen, $aemter),
                lizenznummer: in_array('Übungsleiter', $aemter, true) ? 'UL-' . $nummer : '',
            ));
            $nummer++;
            $nachweis['mitglieder']++;
        }

        // One former member, so "Ehemalige anzeigen" has something to show.
        $this->speichern(new Mitglied(
            mitgliedsnummer: $nummer,
            nachname: 'Vogt',
            vorname: 'Gerda',
            geschlecht: Geschlecht::W,
            geburtsdatum: self::geburtsdatum($heute, 68, 260),
            strasse: 'Schulgasse 9',
            plz: '12345',
            ort: 'Musterstadt',
            eintrittsdatum: self::eintritt($heute, 68, 18),
            austrittsdatum: $heute->modify('-6 months'),
            austrittsgrund: 'Umzug',
            aktiv: false,
            beitragsartId: $beitragsarten['Passiv'] ?? null,
            zahlart: Zahlart::Selbst,
        ));
        $nachweis['mitglieder']++;

        return $nachweis;
    }

    private function speichern(Mitglied $mitglied): void
    {
        $this->mitglieder->speichern($mitglied, VersionsAnlass::angelegt(), null);
    }

    /**
     * Creates the accounts of konten.json anew: an account with the same
     * address is deleted first (its second factor goes with it), so every
     * run starts with an unused authenticator step and the stored mode.
     */
    private function kontenAnlegen(): int
    {
        $daten = json_decode((string) file_get_contents($this->kontenDatei), true, 512, JSON_THROW_ON_ERROR);
        $passwort = (string) ($daten['passwort'] ?? '');
        $benutzer = new BenutzerRepository($this->pdo);
        $hasher = new PasswordHasher();
        $faktor = new ZweiterFaktor(
            new ZweiterFaktorRepository($this->pdo),
            $benutzer,
            new ServerCrypto($this->config->serverKey, $this->config->serverKeyAlt),
            new RateLimiter(new RateLimitRepository($this->pdo)),
        );
        $jetzt = new \DateTimeImmutable();
        $anzahl = 0;

        foreach ($daten['konten'] as $konto) {
            $vorhanden = $benutzer->findByExactEmail($konto['email']);
            if ($vorhanden !== null) {
                $benutzer->delete($vorhanden->id);
            }
            $id = $benutzer->insert(
                $konto['email'],
                $konto['name'],
                $hasher->hash($passwort),
                BenutzerStatus::Aktiv,
                Rolle::from($konto['rolle']),
                $jetzt,
                (bool) $konto['barrierefrei'],
            );
            $geheimnis = Totp::base32Decode($konto['totp_geheimnis']);
            // The code of the step BEFORE this one (the setup accepts one step
            // of drift): a step counts once, and the screenshot script logs in
            // with the current step right after this.
            $code = Totp::code($geheimnis, $jetzt->modify('-30 seconds'));
            if ($faktor->richteAppEin($id, $geheimnis, $code, $jetzt) === null) {
                throw new \RuntimeException('The authenticator app could not be set up for a demo account.');
            }
            $anzahl++;
        }

        return $anzahl;
    }

    /**
     * @param list<object{id: ?int, name: string}> $zeilen
     * @return array<string, int>
     */
    private function idsNachName(array $zeilen): array
    {
        $ids = [];
        foreach ($zeilen as $zeile) {
            if ($zeile->id !== null) {
                $ids[$zeile->name] = $zeile->id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, int> $ids
     * @param list<string> $namen
     * @return list<int>
     */
    private function ids(array $ids, array $namen): array
    {
        $gefunden = [];
        foreach ($namen as $name) {
            if (isset($ids[$name])) {
                $gefunden[] = $ids[$name];
            }
        }

        return $gefunden;
    }

    private static function eintritt(\DateTimeImmutable $heute, int $wird, ?int $seit): \DateTimeImmutable
    {
        $jahre = $seit ?? max(1, min($wird - 6, 12));

        return $heute->modify(sprintf('-%d years -3 months', $jahre));
    }

    private static function telefon(int $nummer): string
    {
        return sprintf('01234 %06d', 560_000 + $nummer);
    }

    private static function handy(int $nummer): string
    {
        return sprintf('0170 %07d', 1_230_000 + $nummer);
    }

    /** Lower case, ASCII only, always @example.org. */
    private static function email(string $vorname, string $nachname): string
    {
        $glatt = static fn(string $text): string => strtolower(strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss',
        ]));

        return $glatt($vorname) . '.' . $glatt($nachname) . '@example.org';
    }
}

// Run as a script, not when a test includes the file.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('zend.exception_ignore_args', '1');
    error_reporting(E_ALL);
    date_default_timezone_set('Europe/Berlin');

    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';

    try {
        $paths = new Paths($root);
        $config = Config::fromFile(getenv('APP_CONFIG_FILE') ?: $paths->configFile());
        $helfer = new HilfeBeispieldaten(
            ConnectionFactory::create($config),
            $config,
            $root . '/' . HilfeBeispieldaten::KONTEN_DATEI,
        );
        $ergebnis = $helfer->anlegen();
        fwrite(STDOUT, sprintf(
            "Beispieldaten angelegt: %d Mitglieder, %d Familien, %d Zugänge.\n",
            $ergebnis['mitglieder'],
            $ergebnis['familien'],
            $ergebnis['konten'],
        ));
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, $e instanceof \RuntimeException ? $e->getMessage() . "\n" : 'Fehler: ' . $e::class . "\n");
        exit(1);
    }
}
