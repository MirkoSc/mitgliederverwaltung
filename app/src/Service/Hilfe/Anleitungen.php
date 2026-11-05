<?php

declare(strict_types=1);

namespace App\Service\Hilfe;

use App\Dienste;
use App\Domain\Permission;
use App\Domain\Rolle;

/**
 * The guides of the help (docs/spec/12-hilfe.md): one file per guide under
 * app/data/hilfe/, named NN-slug.php. There is no central list - the
 * directory is the extension point (05 section 6), so whoever writes a
 * guide touches nothing but their own file. NN sets the order, slug the
 * address.
 *
 * Loading is strict: a file that breaks a rule of 12 section 2 throws
 * UngueltigeAnleitung instead of being skipped or patched up, and the tests
 * load every real file - a broken guide never reaches a release.
 */
final class Anleitungen
{
    /** 12 section 2: more steps are an error (the writing rule says 10). */
    public const int MAX_SCHRITTE = 12;

    /** The slug of an address: /app/hilfe/{slug}. */
    public const string SLUG = '[a-z0-9-]+';

    private const string DATEINAME = '/^(\d{2})-(' . self::SLUG . ')\.php$/';

    private const array SCHLUESSEL = ['titel', 'kurz', 'recht', 'schritte'];

    private const array SCHRITT_SCHLUESSEL = ['text', 'bild', 'tipp'];

    /** @var list<Anleitung>|null */
    private ?array $alle = null;

    public function __construct(
        /** Where the NN-slug.php files are. Missing = no guides yet. */
        private readonly string $verzeichnis,
        /** Where the pictures are: <slug>/<NN>-normal.png etc. */
        private readonly string $bildVerzeichnis,
        /** The same directory as seen from the browser. */
        private readonly string $bildUrl = '/img/hilfe',
        /** Appended as ?v=, like every other asset (cache busting, 01). */
        private readonly string $version = '',
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->paths->dataDir() . '/hilfe',
            $d->paths->publicDir() . '/img/hilfe',
            '/img/hilfe',
            $d->version->value,
        ));
    }

    /**
     * Every guide, in the order of the file names.
     *
     * @return list<Anleitung>
     * @throws UngueltigeAnleitung
     */
    public function alle(): array
    {
        if ($this->alle !== null) {
            return $this->alle;
        }

        $dateien = is_dir($this->verzeichnis) ? (glob($this->verzeichnis . '/*.php') ?: []) : [];
        sort($dateien, SORT_STRING);

        $alle = [];
        foreach ($dateien as $datei) {
            $anleitung = self::laden($datei);
            if (isset($alle[$anleitung->slug])) {
                throw UngueltigeAnleitung::in($datei, sprintf('the slug "%s" is already taken', $anleitung->slug));
            }
            $alle[$anleitung->slug] = $anleitung;
        }

        return $this->alle = array_values($alle);
    }

    /**
     * The guides this role may carry out (12 section 1).
     *
     * @return list<Anleitung>
     */
    public function fuer(Rolle $rolle): array
    {
        return array_values(array_filter($this->alle(), static fn(Anleitung $a): bool => $a->erlaubtFuer($rolle)));
    }

    /** One guide - null when it does not exist or the role may not carry it out. */
    public function finde(string $slug, Rolle $rolle): ?Anleitung
    {
        foreach ($this->fuer($rolle) as $anleitung) {
            if ($anleitung->slug === $slug) {
                return $anleitung;
            }
        }

        return null;
    }

    /** The next guide the role may carry out, for "Nächste Anleitung". */
    public function naechste(Anleitung $nach, Rolle $rolle): ?Anleitung
    {
        $erlaubt = $this->fuer($rolle);
        foreach ($erlaubt as $i => $anleitung) {
            if ($anleitung->slug === $nach->slug) {
                return $erlaubt[$i + 1] ?? null;
            }
        }

        return null;
    }

    /**
     * The picture of a step in the variant of the account's mode - null
     * when the step has none or the file is missing: the text carries the
     * step alone (12 section 3).
     */
    public function bild(Anleitung $anleitung, Schritt $schritt, bool $barrierefrei): ?Bild
    {
        if ($schritt->bild === null) {
            return null;
        }

        $name = $anleitung->slug . '/' . $schritt->bildNummer() . ($barrierefrei ? '-barrierefrei.png' : '-normal.png');
        $datei = $this->bildVerzeichnis . '/' . $name;
        if (!is_file($datei)) {
            return null;
        }
        $groesse = getimagesize($datei);
        if ($groesse === false || $groesse[0] < 1 || $groesse[1] < 1) {
            return null;
        }

        return new Bild(
            $this->bildUrl . '/' . $name . ($this->version === '' ? '' : '?v=' . rawurlencode($this->version)),
            $schritt->bild,
            $groesse[0],
            $groesse[1],
        );
    }

    /**
     * What tools/hilfe-anleitungen.php prints as JSON: slug, title, right
     * and the numbers of the steps with a picture of every guide - so the
     * screenshot script (#115) reads the same data as the application.
     *
     * @return list<array{slug: string, titel: string, recht: ?string, bilder: list<int>}>
     */
    public function steckbriefe(): array
    {
        return array_map(static fn(Anleitung $a): array => [
            'slug' => $a->slug,
            'titel' => $a->titel,
            'recht' => $a->recht?->value,
            'bilder' => $a->schritteMitBild(),
        ], $this->alle());
    }

    /**
     * Reads one guide file and checks every rule of 12 section 2.
     *
     * @throws UngueltigeAnleitung
     */
    public static function laden(string $datei): Anleitung
    {
        if (preg_match(self::DATEINAME, basename($datei), $treffer) !== 1) {
            throw UngueltigeAnleitung::in($datei, 'the file name must be NN-slug.php (two digits, then a-z, 0-9 and "-")');
        }

        // In a scope of its own: the file sees none of our variables.
        $daten = (static fn(string $pfad): mixed => require $pfad)($datei);
        if (!is_array($daten)) {
            throw UngueltigeAnleitung::in($datei, 'the file must return an array');
        }

        $fehlt = array_diff(self::SCHLUESSEL, array_keys($daten));
        if ($fehlt !== []) {
            throw UngueltigeAnleitung::in($datei, sprintf('the key "%s" is missing', reset($fehlt)));
        }
        $unbekannt = array_diff(array_keys($daten), self::SCHLUESSEL);
        if ($unbekannt !== []) {
            throw UngueltigeAnleitung::in($datei, sprintf('unknown key "%s"', reset($unbekannt)));
        }

        $titel = self::text($datei, $daten['titel'], 'titel');
        $kurz = self::text($datei, $daten['kurz'], 'kurz');

        $recht = $daten['recht'];
        if ($recht !== null && !$recht instanceof Permission) {
            throw UngueltigeAnleitung::in($datei, '"recht" must be null or an App\Domain\Permission');
        }

        $roh = $daten['schritte'];
        if (!is_array($roh) || $roh === [] || !array_is_list($roh)) {
            throw UngueltigeAnleitung::in($datei, '"schritte" must be a non-empty list');
        }
        if (count($roh) > self::MAX_SCHRITTE) {
            throw UngueltigeAnleitung::in($datei, sprintf('more than %d steps', self::MAX_SCHRITTE));
        }

        $schritte = [];
        foreach ($roh as $i => $schritt) {
            $nummer = $i + 1;
            $wo = sprintf('step %d', $nummer);
            if (!is_array($schritt)) {
                throw UngueltigeAnleitung::in($datei, $wo . ' must be an array');
            }
            $unbekannt = array_diff(array_keys($schritt), self::SCHRITT_SCHLUESSEL);
            if ($unbekannt !== []) {
                throw UngueltigeAnleitung::in($datei, sprintf('%s: unknown key "%s"', $wo, reset($unbekannt)));
            }
            if (!array_key_exists('text', $schritt)) {
                throw UngueltigeAnleitung::in($datei, $wo . ': the key "text" is missing');
            }

            $schritte[] = new Schritt(
                $nummer,
                self::text($datei, $schritt['text'], $wo . ' text'),
                array_key_exists('bild', $schritt) ? self::text($datei, $schritt['bild'], $wo . ' bild') : null,
                array_key_exists('tipp', $schritt) ? self::text($datei, $schritt['tipp'], $wo . ' tipp') : null,
            );
        }

        return new Anleitung($treffer[2], $titel, $kurz, $recht, $schritte);
    }

    private static function text(string $datei, mixed $wert, string $was): string
    {
        if (!is_string($wert) || trim($wert) === '') {
            throw UngueltigeAnleitung::in($datei, sprintf('"%s" must be a non-empty text', $was));
        }

        return trim($wert);
    }
}
