<?php

declare(strict_types=1);

namespace App\Tests\Installer;

use App\Config\Config;
use App\Installer\ConfigWriter;
use PHPUnit\Framework\TestCase;

/**
 * shared/config.php as the installer writes it (04 section 2): readable by
 * App\Config\Config, the server key as given, a new cron token every time,
 * 0600, and nothing half-written left behind.
 */
final class ConfigWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mv_config_' . bin2hex(random_bytes(4)) . '/shared';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $datei) {
            unlink($datei);
        }
        @rmdir($this->dir);
        @rmdir(dirname($this->dir));
    }

    /** @return array{host: string, port: int, name: string, user: string, password: string} */
    private static function db(): array
    {
        return ['host' => 'localhost', 'port' => 3307, 'name' => 'verein', 'user' => 'verein', 'password' => "pa'ss\\wort"];
    }

    public function testTheWrittenFileIsWhatTheApplicationReads(): void
    {
        $schluessel = ConfigWriter::neuerServerSchluessel();
        $cronToken = ConfigWriter::schreiben($this->dir . '/config.php', self::db(), $schluessel);

        $config = Config::fromFile($this->dir . '/config.php');
        self::assertSame('localhost', $config->dbHost);
        self::assertSame(3307, $config->dbPort);
        self::assertSame("pa'ss\\wort", $config->dbPassword);
        self::assertSame(base64_decode($schluessel, true), $config->serverKey);
        self::assertSame($cronToken, $config->cronToken);
        self::assertFalse($config->debug);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/D', $cronToken);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/config.php')), -4));
        self::assertSame([$this->dir . '/config.php'], glob($this->dir . '/*'), 'no temporary file left behind');
    }

    public function testEveryWriteHasItsOwnCronTokenAndEveryKeyIsNew(): void
    {
        $schluessel = ConfigWriter::neuerServerSchluessel();

        self::assertNotSame(
            ConfigWriter::schreiben($this->dir . '/config.php', self::db(), $schluessel),
            ConfigWriter::schreiben($this->dir . '/config.php', self::db(), $schluessel),
        );
        self::assertNotSame($schluessel, ConfigWriter::neuerServerSchluessel());
        self::assertSame(Config::SERVER_KEY_BYTES, strlen((string) base64_decode($schluessel, true)));
    }

    public function testABrokenKeyNeverBecomesAConfigFile(): void
    {
        try {
            ConfigWriter::schreiben($this->dir . '/config.php', self::db(), 'kein-schluessel');
            self::fail('expected an exception');
        } catch (\App\Config\ConfigException) {
            self::assertFileDoesNotExist($this->dir . '/config.php');
        }
    }
}
