<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Service\Migration\Migration;
use App\Service\Migration\Migrator;

// Development/CLI entry point only - the installer and the updater use the
// Migrator class directly (docs/spec/03-datenhaltung.md section 4).
//
//   docker compose exec app php bin/migrate.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

ini_set('zend.exception_ignore_args', '1');
ini_set('zend.exception_string_param_max_len', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$releaseRoot = dirname(__DIR__);

require $releaseRoot . '/vendor/autoload.php';

$paths = new Paths($releaseRoot);

try {
    $config = Config::fromFile(getenv('APP_CONFIG_FILE') ?: $paths->configFile());
    $migrator = new Migrator(ConnectionFactory::create($config), $paths->migrationsDir());

    if ($migrator->pending() === []) {
        echo sprintf("Schema version %d, nothing to apply.\n", $migrator->currentVersion());
        exit(0);
    }

    $result = $migrator->migrate(static function (Migration $migration): void {
        echo sprintf("Applying %03d_%s ...\n", $migration->version, $migration->name);
    });

    echo sprintf(
        "Applied %d migration(s), schema version %d -> %d.\n",
        count($result->applied),
        $result->fromVersion,
        $result->toVersion,
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
