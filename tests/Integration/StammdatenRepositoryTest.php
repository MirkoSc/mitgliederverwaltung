<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Abteilung;
use App\Domain\AktivArt;
use App\Domain\Beitragsart;
use App\Domain\BeitragsRegel;
use App\Domain\Funktion;
use App\Domain\Mitglied;
use App\Domain\VersionsAnlass;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\FunktionRepository;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * Seeds and master data: departments, functions, fee types
 * (docs/spec/07-mitglieder.md sections 2 and 4, 08 section 1, 09 section 3).
 */
final class StammdatenRepositoryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
    }

    public function testDepartmentSeed(): void
    {
        $abteilungen = new AbteilungRepository($this->pdo())->alle();

        self::assertSame(
            [['Badminton', '01'], ['Fußball', '09'], ['Kegeln', '16'], ['Gymnastik', '34']],
            array_map(static fn(Abteilung $a): array => [$a->name, $a->blsvNummer], $abteilungen),
        );
        self::assertSame([true, true, true, true], array_map(static fn(Abteilung $a): bool => $a->aktiv, $abteilungen));
    }

    public function testFunctionSeed(): void
    {
        $funktionen = new FunktionRepository($this->pdo())->alle();

        self::assertSame(
            [['Übungsleiter', true], ['Wirtschaftshelfer', false], ['Bahndienst', false], ['Jugendtrainer', false]],
            array_map(static fn(Funktion $f): array => [$f->name, $f->mitLizenz], $funktionen),
        );
    }

    public function testFeeTypeSeed(): void
    {
        $arten = new BeitragsartRepository($this->pdo())->alle();

        self::assertSame(
            [
                ['Aktiv', 8500, null, AktivArt::Aktiv],
                ['Passiv', 5000, null, AktivArt::Passiv],
                ['Jugend/Schüler/Studenten/Azubis', 4500, BeitragsRegel::Jugend, AktivArt::Egal],
                ['Familie', 13000, BeitragsRegel::Familie, AktivArt::Egal],
                ['Rentner aktiv', 5000, BeitragsRegel::Rentner, AktivArt::Aktiv],
                ['Rentner passiv', 3000, BeitragsRegel::Rentner, AktivArt::Passiv],
                ['Mindestbeitrag', 2500, null, AktivArt::Egal],
                ['Beitragsfrei', 0, BeitragsRegel::Beitragsfrei, AktivArt::Egal],
            ],
            array_map(static fn(Beitragsart $b): array => [$b->name, $b->betragCent, $b->regel, $b->aktivArt], $arten),
        );
    }

    public function testDefaultSettingsOfTheLists(): void
    {
        $settings = new SettingRepository($this->pdo());

        self::assertSame([18, 30, 40, 50, 60, 65, 70, 75, 80, 85, 90, 95, 100], $settings->get('runde_geburtstage'));
        self::assertSame([25, 40, 50, 60, 70], $settings->get('ehrungsstufen'));
    }

    public function testCreateChangeAndOrder(): void
    {
        $repo = new AbteilungRepository($this->pdo());
        $neu = $repo->speichern(new Abteilung('Tischtennis', '42', sortierung: 5));

        self::assertNotNull($neu->id);
        self::assertSame('Tischtennis', $repo->alle()[0]->name, 'sorted by sortierung');

        $repo->speichern($neu->mit(['name' => 'Tischtennis & Co', 'aktiv' => false]));
        self::assertSame('Tischtennis & Co', $repo->finde($neu->id)?->name);
        self::assertNotContains($neu->id, array_map(static fn(Abteilung $a): ?int => $a->id, $repo->alle(nurAktive: true)));

        $funktionen = new FunktionRepository($this->pdo());
        $kassierer = $funktionen->speichern(new Funktion('Kassenprüfer', mitLizenz: true, sortierung: 50));
        self::assertTrue($funktionen->finde((int) $kassierer->id)?->mitLizenz);

        $arten = new BeitragsartRepository($this->pdo());
        $art = $arten->speichern(new Beitragsart('Ehrenmitglied', 0, BeitragsRegel::Beitragsfrei, sortierung: 90));
        $arten->speichern($art->mit(['betragCent' => 1250, 'regel' => null, 'aktivArt' => AktivArt::Passiv]));
        $gelesen = $arten->finde((int) $art->id);
        self::assertSame(1250, $gelesen?->betragCent);
        self::assertNull($gelesen->regel);
        self::assertSame(AktivArt::Passiv, $gelesen->aktivArt);
    }

    /** Deleting only without members, otherwise deactivating (07 section 2, 08 section 1). */
    public function testDeletingSomethingInUseDeactivatesIt(): void
    {
        $abteilungen = new AbteilungRepository($this->pdo());
        $funktionen = new FunktionRepository($this->pdo());
        $arten = new BeitragsartRepository($this->pdo());
        [$fussball, $kegeln] = [$abteilungen->alle()[1], $abteilungen->alle()[2]];
        [$uebungsleiter, $bahndienst] = [$funktionen->alle()[0], $funktionen->alle()[2]];
        [$aktiv, $passiv] = [$arten->alle()[0], $arten->alle()[1]];

        new MitgliedRepository($this->pdo())->speichern(
            new Mitglied(1, 'Muster', abteilungIds: [(int) $fussball->id], beitragsartId: $aktiv->id, funktionIds: [(int) $uebungsleiter->id]),
            VersionsAnlass::angelegt(),
            null,
        );

        self::assertFalse($abteilungen->loeschen((int) $fussball->id));
        self::assertFalse($abteilungen->finde((int) $fussball->id)?->aktiv);
        self::assertTrue($abteilungen->loeschen((int) $kegeln->id));
        self::assertNull($abteilungen->finde((int) $kegeln->id));

        self::assertFalse($funktionen->loeschen((int) $uebungsleiter->id));
        self::assertFalse($funktionen->finde((int) $uebungsleiter->id)?->aktiv);
        self::assertTrue($funktionen->loeschen((int) $bahndienst->id));
        self::assertNull($funktionen->finde((int) $bahndienst->id));

        self::assertFalse($arten->loeschen((int) $aktiv->id));
        self::assertFalse($arten->finde((int) $aktiv->id)?->aktiv);
        self::assertTrue($arten->loeschen((int) $passiv->id));
        self::assertNull($arten->finde((int) $passiv->id));
    }
}
