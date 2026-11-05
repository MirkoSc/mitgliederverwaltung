<?php

/**
 * Development helper, not part of a release (docs/spec/12-hilfe.md
 * section 2): prints every guide of the help as JSON - slug, title, right
 * and the numbers of the steps with a picture - so the screenshot script
 * (tools/hilfe-screenshots/, issue #115) reads the same data as the
 * application. Loads the files as strictly as the application does: a
 * broken guide ends with exit code 1 and the reason on STDERR.
 *
 *   php tools/hilfe-anleitungen.php [<verzeichnis>]
 *
 * Without an argument it reads app/data/hilfe/.
 *
 *   [{"slug":"anmelden","titel":"Anmelden und abmelden","recht":null,"bilder":[1,3]}, ...]
 */

declare(strict_types=1);

use App\Service\Hilfe\Anleitungen;
use App\Service\Hilfe\UngueltigeAnleitung;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

ini_set('zend.exception_ignore_args', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$verzeichnis = $argv[1] ?? $root . '/app/data/hilfe';

try {
    // Parenthesised: the script may run under an older PHP on the developer machine.
    $steckbriefe = (new Anleitungen($verzeichnis, $root . '/public/img/hilfe'))->steckbriefe();
} catch (UngueltigeAnleitung $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo json_encode($steckbriefe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
