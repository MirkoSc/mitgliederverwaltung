<?php

declare(strict_types=1);

/**
 * Old member software (two xlsx exports) -> import.csv for the application.
 * Runs locally, the files never leave the computer (docs/spec/10-import.md
 * section 3). Not part of a release.
 *
 *   docker compose run --rm app php tools/altdaten-umwandeln.php \
 *       import-daten/mitglieder.xlsx import-daten/familien.xlsx > import-daten/import.csv
 *
 * import.csv goes to STDOUT, a summary without names to STDERR. Optional
 * --stichtag=TT.MM.JJJJ fixes the day "under 18" is measured on (default: today).
 */

require_once __DIR__ . '/altdaten/XlsxLeser.php';
require_once __DIR__ . '/altdaten/Zaehler.php';
require_once __DIR__ . '/altdaten/Ergebnis.php';
require_once __DIR__ . '/altdaten/Umwandler.php';
require_once __DIR__ . '/altdaten/Kommandozeile.php';

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(\Tools\Altdaten\Kommandozeile::ausfuehren(array_slice($argv, 1), STDOUT, STDERR));
}
