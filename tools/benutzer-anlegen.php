<?php

/**
 * Development helper, not part of a release: creates an active admin in the
 * database of config.php - the way into a fresh docker environment until the
 * installer (issue #3) creates the first admin.
 *
 *   docker compose exec app php bin/migrate.php
 *   docker compose exec app php tools/benutzer-anlegen.php admin@example.org "Erika Beispiel"
 *
 * The password is read from STDIN (twice), never from the command line,
 * where it would end up in the shell history. It goes through the same rule
 * as every other password (App\Service\Account\PasswordPolicy).
 */

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Service\Account\BenutzerRegelVerstoss;
use App\Service\Account\BenutzerVerwaltung;
use App\Service\Account\PasswordPolicy;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

ini_set('zend.exception_ignore_args', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

[$email, $name] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($email === '' || $name === '') {
    fwrite(STDERR, "Usage: php tools/benutzer-anlegen.php <e-mail> \"<name>\"\n");
    exit(2);
}

$lies = static function (string $frage): string {
    fwrite(STDOUT, $frage);
    $zeile = fgets(STDIN);

    return $zeile === false ? '' : rtrim($zeile, "\r\n");
};

$paths = new Paths($root);
$passwort = $lies('Passwort: ');
$wiederholung = $lies('Passwort wiederholen: ');

try {
    $config = Config::fromFile(getenv('APP_CONFIG_FILE') ?: $paths->configFile());
    $verwaltung = new BenutzerVerwaltung(
        ConnectionFactory::create($config),
        new PasswordPolicy($paths->dataDir() . '/haeufige-passwoerter.txt'),
    );
    $id = $verwaltung->ersterAdmin($email, $name, $passwort, $wiederholung);
    fwrite(STDOUT, sprintf("Admin angelegt (ID %d). Anmelden unter /anmelden.\n", $id));
    exit(0);
} catch (BenutzerRegelVerstoss $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e::class . "\n");
    exit(1);
}
