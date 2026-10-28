<?php

declare(strict_types=1);

namespace Tools\Altdaten;

/**
 * Command line of tools/altdaten-umwandeln.php, separated from the script so
 * tests can run it on memory streams: import.csv goes to $ausgabe, the
 * summary (counts only, no names) and errors to $fehler.
 */
final class Kommandozeile
{
    /**
     * @param list<string> $argumente without the script name
     * @param resource $ausgabe
     * @param resource $fehler
     */
    public static function ausfuehren(array $argumente, $ausgabe, $fehler): int
    {
        $stichtag = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $dateien = [];
        foreach ($argumente as $argument) {
            if (str_starts_with($argument, '--stichtag=')) {
                $gelesen = \DateTimeImmutable::createFromFormat('!d.m.Y', substr($argument, strlen('--stichtag=')));
                if ($gelesen === false) {
                    fwrite($fehler, "Stichtag bitte als TT.MM.JJJJ angeben.\n");

                    return 2;
                }
                $stichtag = $gelesen;
            } else {
                $dateien[] = $argument;
            }
        }
        if (count($dateien) !== 2) {
            fwrite($fehler, "Aufruf: php tools/altdaten-umwandeln.php mitglieder.xlsx familien.xlsx [--stichtag=TT.MM.JJJJ] > import.csv\n");

            return 2;
        }

        try {
            $ergebnis = (new Umwandler($stichtag))->umwandeln(XlsxLeser::lesen($dateien[0]), XlsxLeser::lesen($dateien[1]));
        } catch (\RuntimeException $e) {
            fwrite($fehler, 'Abbruch: ' . $e->getMessage() . "\n");

            return 1;
        }

        fwrite($ausgabe, $ergebnis->csv());
        fwrite($fehler, $ergebnis->zusammenfassung());

        return 0;
    }
}
