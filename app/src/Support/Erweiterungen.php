<?php

declare(strict_types=1);

namespace App\Support;

use App\Dienste;
use App\Domain\Konto;

/**
 * Finds the extensions of one kind by convention, without a list anyone
 * would have to edit (docs/spec/05-entwicklung.md section 6): every file
 * `<Name><Endung>.php` in the given directory whose class implements the
 * given interface. A new settings section, list or member tab is therefore
 * one new file - and nothing else.
 *
 * @template T of Erweiterung
 */
final readonly class Erweiterungen
{
    private const string SCHLUESSEL_MUSTER = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /**
     * @param class-string<T> $schnittstelle
     * @param string $endung class name suffix, e.g. "Abschnitt"
     */
    public function __construct(
        private string $schnittstelle,
        private string $verzeichnis,
        private string $namespace,
        private string $endung,
    ) {
    }

    /**
     * @return list<class-string<T>> in file name order
     */
    public function klassen(): array
    {
        $dateien = glob($this->verzeichnis . '/*' . $this->endung . '.php') ?: [];
        sort($dateien, SORT_STRING);

        $klassen = [];
        foreach ($dateien as $datei) {
            $kurz = basename($datei, '.php');
            if ($kurz === $this->endung) {
                continue; // the interface itself
            }
            $klasse = $this->namespace . '\\' . $kurz;
            if (!class_exists($klasse) || !is_subclass_of($klasse, $this->schnittstelle)) {
                continue;
            }
            if (!new \ReflectionClass($klasse)->isInstantiable()) {
                continue;
            }
            $klassen[] = $klasse;
        }

        return $klassen;
    }

    /**
     * Every extension, sorted for display; with an account only those it may
     * see.
     *
     * @return list<T>
     */
    public function alle(Dienste $dienste, ?Konto $konto = null): array
    {
        $alle = [];
        foreach ($this->klassen() as $klasse) {
            $erweiterung = $klasse::erzeugen($dienste);
            assert($erweiterung instanceof $this->schnittstelle);
            $schluessel = $erweiterung->schluessel();
            if (preg_match(self::SCHLUESSEL_MUSTER, $schluessel) !== 1) {
                throw new \LogicException(sprintf('%s: key "%s" is not lowercase-with-hyphens.', $klasse, $schluessel));
            }
            if (isset($alle[$schluessel])) {
                throw new \LogicException(sprintf('%s: key "%s" is taken by %s.', $klasse, $schluessel, $alle[$schluessel]::class));
            }
            $alle[$schluessel] = $erweiterung;
        }

        $sichtbar = array_values(array_filter(
            $alle,
            static fn(Erweiterung $e): bool => $konto === null || $konto->darf($e->recht()),
        ));
        usort(
            $sichtbar,
            static fn(Erweiterung $a, Erweiterung $b): int => [$a->reihenfolge(), $a->titel()] <=> [$b->reihenfolge(), $b->titel()],
        );

        return $sichtbar;
    }

    /**
     * One extension by key, or null - also null when the account may not
     * see it.
     *
     * @return T|null
     */
    public function finde(Dienste $dienste, string $schluessel, ?Konto $konto = null): ?Erweiterung
    {
        foreach ($this->alle($dienste, $konto) as $erweiterung) {
            if ($erweiterung->schluessel() === $schluessel) {
                return $erweiterung;
            }
        }

        return null;
    }
}
