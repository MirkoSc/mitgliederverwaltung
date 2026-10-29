<?php

declare(strict_types=1);

// Generates setup.php (repo root) from bin/setup.template.php by inlining
// the shared classes - the download/verify/extract code of setup.php and the
// self-updater is literally the same, and so is the .htaccess that locks
// shared/ and releases/ (docs/spec/04-installation-und-update.md section 2).
// Taken over from Vereinsbelege. CI fails when setup.php is stale.
//
//   php bin/build_setup.php
//
// Required from another file (tests/Installer/SetupPhpTest.php) it only
// returns the builder and writes nothing.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * @return string the content of setup.php
 */
$bauen = static function (string $root): string {
    // All of them live in App\Service\Update and depend on nothing else.
    $classFiles = [
        $root . '/app/src/Service/Update/ReleaseDownloader.php',
        $root . '/app/src/Service/Update/ReleaseSignatur.php',
        $root . '/app/src/Service/Update/DenyHtaccess.php',
    ];

    $template = file_get_contents($root . '/bin/setup.template.php');
    if ($template === false) {
        throw new RuntimeException('Cannot read bin/setup.template.php.');
    }

    $classBodies = [];
    foreach ($classFiles as $classFile) {
        $classSource = file_get_contents($classFile);
        if ($classSource === false) {
            throw new RuntimeException('Cannot read ' . basename($classFile) . '.');
        }
        // strip opening tag, strict_types and the namespace declaration; the
        // template provides its own namespace block
        $classBodies[] = trim((string) preg_replace(
            ['/^<\?php\s*/', '/declare\(strict_types=1\);\s*/', '/namespace\s+App\\\\Service\\\\Update;\s*/'],
            '',
            $classSource,
        ));
    }

    return str_replace('//__SHARED_CODE__', implode("\n\n", $classBodies), $template);
};

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return $bauen;
}

$setup = $bauen(dirname(__DIR__));
if (file_put_contents(dirname(__DIR__) . '/setup.php', $setup) === false) {
    fwrite(STDERR, "Cannot write setup.php.\n");
    exit(1);
}
echo 'setup.php generated (' . strlen($setup) . " bytes)\n";
exit(0);
