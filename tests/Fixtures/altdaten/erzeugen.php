<?php

declare(strict_types=1);

/**
 * Rebuilds mitglieder.xlsx and familien.xlsx from daten.php (invented data):
 *
 *   docker compose run --rm app php tests/Fixtures/altdaten/erzeugen.php
 */

require __DIR__ . '/../../../vendor/autoload.php';

$daten = require __DIR__ . '/daten.php';

// The second sheet of the member file holds junk that must never be read.
\App\Tests\Support\XlsxSchreiber::schreiben(__DIR__ . '/mitglieder.xlsx', $daten['mitglieder'], [['Adressnummer', 'Nachname'], [1, 'Zweites Blatt']]);
\App\Tests\Support\XlsxSchreiber::schreiben(__DIR__ . '/familien.xlsx', $daten['familien']);
echo "mitglieder.xlsx und familien.xlsx geschrieben\n";
