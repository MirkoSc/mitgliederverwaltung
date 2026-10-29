<?php

declare(strict_types=1);

use App\Anwendung;
use App\Config\Config;
use App\Config\Paths;
use App\Dienste;
use App\Http\Session;
use App\Support\FileLogger;
use App\Support\Version;
use App\View\Stylesheets;
use App\View\View;

// -------------------------------------------------------------------------
// FIRST statements of the application, before an autoloader, a config file
// or a single line of our own code can throw.
//
// The target host ships zend.exception_ignore_args = 0, which puts every
// function's ARGUMENTS into stack traces - the plaintext password of a login
// attempt, member data of a form. CLAUDE.md section 4 requires it on. The
// value is PHP_INI_ALL, so setting it here takes effect; docker/web/.user.ini
// covers the window before this file runs, and App\Http\Kernel never logs the
// full exception string regardless.
// -------------------------------------------------------------------------
ini_set('zend.exception_ignore_args', '1');
// Belt to those braces: even where arguments ARE kept, this caps every
// string parameter in a trace to '...'.
ini_set('zend.exception_string_param_max_len', '0');
// Also set by web/.user.ini; here too, should that file be missing or edited.
ini_set('display_errors', '0');

// The ONLY place for global runtime setup: everything is stored and
// interpreted as Europe/Berlin (CLAUDE.md section 5).
error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$releaseRoot = dirname(__DIR__, 2);

require $releaseRoot . '/vendor/autoload.php';

$paths = new Paths($releaseRoot);
$version = Version::fromFile($paths->versionFile());
$logger = new FileLogger($paths->logFile());

// Warnings, notices and deprecations go into shared/var/log/ as well - the
// host's own PHP log is hard to reach from the customer panel. Message and
// place only; returning false keeps PHP's own handling (production does not
// display them, docker/web/.user.ini).
set_error_handler(static function (int $nummer, string $text, string $datei, int $zeile) use ($logger): bool {
    if ((error_reporting() & $nummer) === 0) {
        return false; // silenced with @
    }
    $logger->append(sprintf('PHP error %d: %s at %s:%d', $nummer, $text, $datei, $zeile));

    return false;
});

$view = new View($paths->viewsDir(), $version->value, Stylesheets::aus($paths->publicDir()));
$configFile = getenv('APP_CONFIG_FILE') ?: $paths->configFile();

return is_file($configFile)
    ? Anwendung::kernel(new Dienste($paths, Config::fromFile($configFile), $version, $view, new Session(), $logger))
    : Anwendung::installation($paths, $view, $logger);
