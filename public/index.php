<?php

declare(strict_types=1);

use App\Http\Kernel;
use App\Http\Request;

// Repeated here and not only in bootstrap.php: this file runs FIRST, and
// everything below - including the require of bootstrap.php - can throw
// before that file's own ini_set() is reached. A trace rendered or logged
// from the catch block must not carry call arguments either.
ini_set('zend.exception_ignore_args', '1');
ini_set('zend.exception_string_param_max_len', '0');

// Maintenance mode: set while the updater switches releases. The docroot
// shim performs the same check one level up (between the two renames of a
// switch this file does not exist); this copy covers an installation whose
// shim is older. /admin stays reachable for the update step chain, the login
// for an admin whose session ran out meanwhile, and the assets those pages
// need - the same list as the shim.
$maintenanceFlag = dirname(__DIR__, 2) . '/shared/maintenance.flag';
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$maintenanceBypass = str_starts_with($requestPath, '/admin')
    || $requestPath === '/anmelden'
    || str_starts_with($requestPath, '/anmelden/')
    || str_starts_with($requestPath, '/css/')
    || str_starts_with($requestPath, '/js/');
if (is_file($maintenanceFlag) && !$maintenanceBypass) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 30');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Wartung</title></head>'
        . '<body><h1>Kurze Wartungspause</h1><p>Die Mitgliederverwaltung wird gerade aktualisiert. '
        . 'Bitte in einer Minute erneut laden.</p></body></html>';
    exit;
}

// Bootstrap runs OUTSIDE the Kernel's own error handler, and things do fail
// there: a missing VERSION file, an unreadable config, a broken autoloader.
// Deliberately hand-rolled rather than using Support\FileLogger: the
// autoloader may itself be the thing that failed. Same redaction rule as
// Http\Kernel - class, message, method, path, file, line, never the full
// exception string, and never the message of a database error (it quotes
// row data).
$protokollieren = static function (string $art, \Throwable $e) use ($requestPath): void {
    $zeile = sprintf(
        '[%s] %s %s: %s [%s %s] at %s:%d',
        date('c'),
        $art,
        $e::class,
        $e instanceof \PDOException ? 'SQLSTATE ' . $e->getCode() . ' (message withheld)' : $e->getMessage(),
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $requestPath,
        $e->getFile(),
        $e->getLine(),
    );

    error_log($zeile);
    $logDatei = dirname(__DIR__, 2) . '/shared/var/log/app.log';
    if (is_dir(dirname($logDatei)) || @mkdir(dirname($logDatei), 0775, true) || is_dir(dirname($logDatei))) {
        @file_put_contents($logDatei, $zeile . "\n", FILE_APPEND | LOCK_EX);
    }
};

try {
    /** @var Kernel $kernel */
    $kernel = require dirname(__DIR__) . '/app/src/bootstrap.php';
    $antwort = $kernel->handle(Request::fromGlobals());
} catch (\Throwable $e) {
    $protokollieren('BOOTSTRAP', $e);

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title></head>'
        . '<body><h1>Interner Fehler</h1><p>Die Mitgliederverwaltung ist gerade nicht erreichbar. '
        . 'Bitte später erneut versuchen.</p></body></html>';
    exit;
}

// Sending is outside the Kernel: a streamed download (an export is produced
// while it is sent) can still fail half way. Then the status line is long
// gone - log it, and append nothing, or the error page would end up inside
// the downloaded file.
try {
    $antwort->send();
} catch (\Throwable $e) {
    $protokollieren('SEND', $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Interner Fehler.';
    }
}
