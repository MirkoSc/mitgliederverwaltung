<?php

declare(strict_types=1);

namespace App\Tests\Installer;

use App\Service\Update\DenyHtaccess;
use App\Service\Update\ReleaseDownloader;
use PHPUnit\Framework\TestCase;

/**
 * setup.php is generated (bin/build_setup.php) from bin/setup.template.php
 * and the update classes it shares with the self-updater
 * (docs/spec/04-installation-und-update.md section 2). CI regenerates it and
 * fails on a diff; this test says the same without git, locally too.
 */
final class SetupPhpTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function inhalt(): string
    {
        return (string) file_get_contents(self::root() . '/setup.php');
    }

    public function testTheCommittedSetupPhpIsWhatTheBuilderMakes(): void
    {
        /** @var \Closure(string): string $bauen */
        $bauen = require self::root() . '/bin/build_setup.php';

        self::assertSame($bauen(self::root()), self::inhalt(), 'setup.php is stale - run php bin/build_setup.php');
    }

    /** No php -l on the target host (no exec), so the parser runs in-process. */
    public function testSetupPhpIsValidPhp(): void
    {
        token_get_all(self::inhalt(), \TOKEN_PARSE);

        self::assertStringStartsWith('<?php', self::inhalt());
    }

    /** One copy of the download code and of the lock: the updater's own. */
    public function testItCarriesTheSharedClassesAndNoCopyOfTheirConstants(): void
    {
        $setup = self::inhalt();

        foreach (['final class ReleaseDownloader', 'final class ReleaseSignatur', 'final class DenyHtaccess'] as $klasse) {
            self::assertSame(1, substr_count($setup, $klasse), $klasse);
        }
        self::assertStringContainsString("'" . ReleaseDownloader::REPO . "'", $setup);
        self::assertStringNotContainsString('//__SHARED_CODE__', $setup);
        self::assertStringContainsString("public const array DIRECTORIES = ['shared', 'releases', 'current'];", $setup);
        self::assertContains('shared', DenyHtaccess::DIRECTORIES);
    }

    /**
     * What 04 section 2 asks of setup.php: the channel goes to the
     * installer in shared/setup_kanal.txt, shared/ and releases/ are locked
     * BEFORE the download, the token is 128 bits in a 0600 file, and the
     * browser is sent on to /install with it.
     */
    public function testChannelLockAndTokenAreHandedToTheInstaller(): void
    {
        $setup = self::inhalt();

        self::assertStringContainsString("'/setup_kanal.txt', \$kanal", $setup);
        self::assertStringContainsString('bin2hex(random_bytes(16))', $setup);
        self::assertStringContainsString("'/install_token.txt', 0600", $setup);
        self::assertStringContainsString("'/install?token=' . \$installToken", $setup);
        self::assertLessThan(
            strpos($setup, '$downloader->downloadTo('),
            strpos($setup, 'DenyHtaccess::ensureAll($rootDir)'),
            'shared/ and releases/ must be locked before anything is downloaded into them',
        );
        // The signature and the checksum are checked before unpacking.
        self::assertLessThan(strpos($setup, '$downloader->extractTo('), strpos($setup, 'ReleaseSignatur::pruefe('));
        self::assertLessThan(strpos($setup, '$downloader->extractTo('), strpos($setup, '$downloader->verifyChecksum('));
    }

    /**
     * 04 section 2: the token goes out only after proof of FTP access - the
     * code of the (uncached) form in setup_freigabe.txt in the data
     * directory, checked before anything is written and removed once
     * current/ exists.
     */
    public function testTheTokenNeedsTheFtpFreigabeFirst(): void
    {
        $setup = self::inhalt();
        // The template part starts here, after the shared classes.
        $vorlage = strpos($setup, "const SETUP_FREIGABE_DATEI = 'setup_freigabe.txt';");
        $pruefung = strpos($setup, '!hash_equals($freigabeErwartet, $freigabe)');

        self::assertNotFalse($vorlage);
        self::assertNotFalse($pruefung);
        self::assertStringContainsString("\$freigabeDatei = \$rootDir . '/' . SETUP_FREIGABE_DATEI;", $setup);
        self::assertStringContainsString("header('Cache-Control: no-store');", $setup);
        foreach ([
            '$downloader->findLatestRelease(',
            'mkdir($dir, 0775, true)',
            "\$sharedDir . '/setup_kanal.txt'",
            "\$sharedDir . '/install_token.txt'",
            '$downloader->downloadTo(',
        ] as $schreibt) {
            self::assertLessThan(strpos($setup, $schreibt, $vorlage), $pruefung, $schreibt);
        }
        self::assertLessThan(
            strpos($setup, '@unlink($freigabeDatei);'),
            strpos($setup, "rename(\$target, \$rootDir . '/current')"),
        );
    }
}
