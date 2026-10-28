<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Admin\Einstellungen\Abschnitt as EinstellungsAbschnitt;
use App\Admin\Einstellungen\Abschnitte;
use App\App\Mitglied\Abschnitt as MitgliedAbschnitt;
use App\App\Mitglied\MitgliedErweiterungen;
use App\App\Mitglied\Reiter;
use App\Domain\Konto;
use App\Domain\Rolle;
use App\Listen\Liste;
use App\Listen\Listen;
use App\Support\Erweiterungen;
use App\Tests\Fixtures\Erweiterungen\BeispielAbschnitt;
use App\Tests\Fixtures\Erweiterungen\GeburtstageBeispielListe;
use App\Tests\Fixtures\Erweiterungen\MitgliedAbschnittBeispielAbschnitt;
use App\Tests\Fixtures\Erweiterungen\MitgliedReiterBeispielReiter;
use App\Tests\Fixtures\Erweiterungen\SelbstKassierenBeispielListe;
use App\Tests\Fixtures\Erweiterungen\VereinBeispielAbschnitt;
use PHPUnit\Framework\TestCase;

/**
 * The extension points of docs/spec/05-entwicklung.md section 6: a new
 * settings section, list, member tab or member section is one new file and
 * is found without a change to any central file.
 *
 * The fixtures in tests/Fixtures/Erweiterungen/ are written the way an
 * issue writes its extension; the registries below are the very ones the
 * application uses, pointed at that directory.
 */
final class ErweiterungenTest extends TestCase
{
    private const string NAMESPACE = 'App\\Tests\\Fixtures\\Erweiterungen';

    private static function verzeichnis(string $name = 'Erweiterungen'): string
    {
        return dirname(__DIR__) . '/Fixtures/' . $name;
    }

    /**
     * @template T of \App\Support\Erweiterung
     * @param class-string<T> $schnittstelle
     * @return Erweiterungen<T>
     */
    private static function registry(string $schnittstelle, string $endung): Erweiterungen
    {
        return new Erweiterungen($schnittstelle, self::verzeichnis(), self::NAMESPACE, $endung);
    }

    public function testANewSettingsSectionIsFoundAndSorted(): void
    {
        $abschnitte = self::registry(EinstellungsAbschnitt::class, 'Abschnitt')->alle(AppTestCase::baueDienste(new TestKonten()));

        // Not the base class, not the class that implements nothing, not the
        // member section that shares the suffix; ordered by reihenfolge().
        self::assertSame(
            [VereinBeispielAbschnitt::class, BeispielAbschnitt::class],
            array_map(static fn(object $a): string => $a::class, $abschnitte),
        );
    }

    public function testANewListIsFoundAndFilteredByTheAccountsRights(): void
    {
        $listen = self::registry(Liste::class, 'Liste');
        $dienste = AppTestCase::baueDienste(new TestKonten());

        self::assertSame(
            [GeburtstageBeispielListe::class, SelbstKassierenBeispielListe::class],
            array_map(static fn(object $l): string => $l::class, $listen->alle($dienste, new Konto(1, 'A', Rolle::Admin))),
        );
        // 09: the board does not see "Selbst kassieren".
        self::assertSame(
            [GeburtstageBeispielListe::class],
            array_map(static fn(object $l): string => $l::class, $listen->alle($dienste, new Konto(2, 'V', Rolle::Vorstand))),
        );
        self::assertNull($listen->finde($dienste, 'selbst-kassieren', new Konto(2, 'V', Rolle::Vorstand)));
        self::assertInstanceOf(SelbstKassierenBeispielListe::class, $listen->finde($dienste, 'selbst-kassieren', new Konto(1, 'A', Rolle::Admin)));
    }

    public function testNewMemberTabsAndSectionsAreFound(): void
    {
        $dienste = AppTestCase::baueDienste(new TestKonten());

        $reiter = self::registry(Reiter::class, 'Reiter')->alle($dienste);
        $abschnitte = self::registry(MitgliedAbschnitt::class, 'Abschnitt')->alle($dienste);

        self::assertSame([MitgliedReiterBeispielReiter::class], array_map(static fn(object $r): string => $r::class, $reiter));
        self::assertSame([MitgliedAbschnittBeispielAbschnitt::class], array_map(static fn(object $a): string => $a::class, $abschnitte));
        self::assertSame(['mitglied' => 42], $reiter[0]->daten(42));
    }

    public function testTwoExtensionsWithTheSameKeyAreAnError(): void
    {
        $registry = new Erweiterungen(EinstellungsAbschnitt::class, self::verzeichnis('ErweiterungenDoppelt'), 'App\\Tests\\Fixtures\\ErweiterungenDoppelt', 'Abschnitt');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('key "beispiel" is taken');

        $registry->alle(AppTestCase::baueDienste(new TestKonten()));
    }

    /**
     * The registries of the application look exactly where 05 section 6
     * tells an issue to put its file.
     */
    public function testTheApplicationsRegistriesLookWhereTheSpecSays(): void
    {
        $src = dirname(__DIR__, 2) . '/app/src';

        self::assertEquals(new Erweiterungen(EinstellungsAbschnitt::class, $src . '/Admin/Einstellungen', 'App\\Admin\\Einstellungen', 'Abschnitt'), Abschnitte::registry());
        self::assertEquals(new Erweiterungen(Liste::class, $src . '/Listen', 'App\\Listen', 'Liste'), Listen::registry());
        self::assertEquals(new Erweiterungen(Reiter::class, $src . '/App/Mitglied', 'App\\App\\Mitglied', 'Reiter'), MitgliedErweiterungen::reiter());
        self::assertEquals(new Erweiterungen(MitgliedAbschnitt::class, $src . '/App/Mitglied', 'App\\App\\Mitglied', 'Abschnitt'), MitgliedErweiterungen::abschnitte());
    }

    /** The interface files share the suffix and must not count as extensions. */
    public function testTheInterfacesThemselvesAreNoExtensions(): void
    {
        self::assertNotContains(EinstellungsAbschnitt::class, Abschnitte::registry()->klassen());
        self::assertNotContains(Liste::class, Listen::registry()->klassen());
        self::assertNotContains(Reiter::class, MitgliedErweiterungen::reiter()->klassen());
        self::assertNotContains(MitgliedAbschnitt::class, MitgliedErweiterungen::abschnitte()->klassen());
    }
}
