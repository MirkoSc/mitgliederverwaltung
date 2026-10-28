<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

final class SettingRepositoryTest extends DatabaseTestCase
{
    private SettingRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->settings = new SettingRepository($this->pdo());
    }

    public function testAMissingSettingGivesTheDefault(): void
    {
        self::assertNull($this->settings->get('gibt_es_nicht'));
        self::assertSame('x', $this->settings->get('gibt_es_nicht', 'x'));
        self::assertSame('vorgabe', $this->settings->text('gibt_es_nicht', 'vorgabe'));
        self::assertSame(3, $this->settings->zahl('gibt_es_nicht', 3));
        self::assertTrue($this->settings->schalter('gibt_es_nicht', true));
    }

    /** The value is JSON: a list stays a list, a number a number. */
    public function testValuesKeepTheirType(): void
    {
        $this->settings->set('runde_geburtstage', [18, 30, 40]);
        $this->settings->set('name', 'Sportverein Beispielstadt');
        $this->settings->set('anzahl', 7);
        $this->settings->set('an', false);

        self::assertSame([18, 30, 40], $this->settings->get('runde_geburtstage'));
        self::assertSame('Sportverein Beispielstadt', $this->settings->text('name'));
        self::assertSame(7, $this->settings->zahl('anzahl'));
        self::assertFalse($this->settings->schalter('an', true));
    }

    public function testATypeMismatchFallsBackToTheDefault(): void
    {
        $this->settings->set('anzahl', 'sieben');

        self::assertSame(1, $this->settings->zahl('anzahl', 1));
    }

    public function testSettingAgainOverwritesAndDeleteRemoves(): void
    {
        $this->settings->set('kanal', 'stable');
        $this->settings->set('kanal', 'beta');
        self::assertSame('beta', $this->settings->text('kanal'));

        $this->settings->delete('kanal');
        self::assertNull($this->settings->get('kanal'));
    }

    public function testUmlautsSurvive(): void
    {
        $this->settings->set('text', 'Grüße aus Würzburg – „ß“');

        self::assertSame('Grüße aus Würzburg – „ß“', $this->settings->text('text'));
    }
}
