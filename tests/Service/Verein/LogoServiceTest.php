<?php

declare(strict_types=1);

namespace App\Tests\Service\Verein;

use App\Config\Paths;
use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;
use App\Service\Verein\LogoException;
use App\Service\Verein\LogoService;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\TestBilder;

final class LogoServiceTest extends DatabaseTestCase
{
    private string $wurzel;

    private LogoService $logo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is not available.');
        }
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        // release root = <wurzel>/current, so shared/ is <wurzel>/shared
        $this->wurzel = sys_get_temp_dir() . '/mv-logo-' . bin2hex(random_bytes(4));
        mkdir($this->wurzel . '/current', 0775, true);
        $this->logo = new LogoService(new Paths($this->wurzel . '/current'), new SettingRepository($this->pdo()));
    }

    protected function tearDown(): void
    {
        if (isset($this->wurzel)) {
            TestBilder::loeschen($this->wurzel);
        }
        parent::tearDown();
    }

    public function testWithoutALogoThereIsNothing(): void
    {
        self::assertNull($this->logo->version());
        self::assertNull($this->logo->datei('kopf'));
    }

    public function testAPngIsDerivedToEverySize(): void
    {
        $this->logo->speichern(TestBilder::png($this->wurzel));

        self::assertNotNull($this->logo->version());
        $erwartet = ['kopf' => [96, 48], 'anmeldung' => [320, 160], 'favicon' => [32, 32], 'apple' => [180, 180]];
        foreach ($erwartet as $groesse => [$breite, $hoehe]) {
            $datei = $this->logo->datei($groesse);
            self::assertNotNull($datei, $groesse);
            $info = getimagesize($datei);
            self::assertSame(IMAGETYPE_PNG, $info[2]);
            self::assertSame([$breite, $hoehe], [$info[0], $info[1]], $groesse);
        }
    }

    public function testAJpegIsAccepted(): void
    {
        $this->logo->speichern(TestBilder::jpeg($this->wurzel));

        $info = getimagesize((string) $this->logo->datei('kopf'));
        self::assertSame([96, 96], [$info[0], $info[1]]);
    }

    public function testASvgIsRefused(): void
    {
        $this->expectException(LogoException::class);

        try {
            $this->logo->speichern(TestBilder::svg($this->wurzel));
        } finally {
            self::assertNull($this->logo->version());
        }
    }

    public function testATextFileWithAnImageNameIsRefused(): void
    {
        $datei = $this->wurzel . '/logo.png';
        file_put_contents($datei, 'kein Bild');

        $this->expectException(LogoException::class);
        $this->logo->speichern($datei);
    }

    public function testAFileOverFiveMegabytesIsRefused(): void
    {
        $datei = TestBilder::png($this->wurzel);
        file_put_contents($datei, str_repeat("\0", LogoService::MAX_BYTES), FILE_APPEND);

        $this->expectException(LogoException::class);
        $this->expectExceptionMessage('5 MB');
        $this->logo->speichern($datei);
    }

    public function testARefusedUploadKeepsTheCurrentLogo(): void
    {
        $this->logo->speichern(TestBilder::png($this->wurzel));
        $version = $this->logo->version();

        try {
            $this->logo->speichern(TestBilder::svg($this->wurzel));
            self::fail('SVG must be refused');
        } catch (LogoException) {
        }

        self::assertSame($version, $this->logo->version());
        self::assertNotNull($this->logo->datei('kopf'));
    }

    public function testRemovingDeletesTheFilesAndTheVersion(): void
    {
        $this->logo->speichern(TestBilder::png($this->wurzel));
        $datei = (string) $this->logo->datei('kopf');

        $this->logo->entfernen();

        self::assertNull($this->logo->version());
        self::assertFileDoesNotExist($datei);
    }
}
