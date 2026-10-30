<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Domain\Konto;
use App\Domain\Rolle;
use App\Http\HttpMethod;
use App\View\NavItem;
use App\View\Navigation;
use PHPUnit\Framework\TestCase;

/** The navigation of docs/spec/06-oberflaeche.md section 2 - complete from #1 on. */
final class NavigationTest extends TestCase
{
    /**
     * @param list<NavItem> $eintraege
     * @return list<string>
     */
    private static function labels(array $eintraege, ?Konto $konto = null): array
    {
        return array_values(array_map(
            static fn(NavItem $e): string => $e->label,
            array_filter($eintraege, static fn(NavItem $e): bool => $konto === null || $e->sichtbarFuer($konto)),
        ));
    }

    public function testTheMainAreasAndNoMore(): void
    {
        self::assertSame(['Mitglieder', 'Listen', 'Karte', 'Beiträge', 'Einstellungen'], self::labels(Navigation::haupt()));
    }

    /** "* nur Admin" in 06 section 2. */
    public function testTheBoardSeesNeitherFeesNorSettings(): void
    {
        self::assertSame(['Mitglieder', 'Listen', 'Karte'], self::labels(Navigation::haupt(), new Konto(1, 'V', Rolle::Vorstand)));
        self::assertSame(['Mitglieder', 'Listen', 'Karte', 'Beiträge', 'Einstellungen'], self::labels(Navigation::haupt(), new Konto(1, 'A', Rolle::Admin)));
    }

    /** Issue #87: "Nur lesen" has neither the page "Beiträge" nor the admin area. */
    public function testReadOnlySeesNeitherFeesPageNorSettings(): void
    {
        self::assertSame(['Mitglieder', 'Listen'], self::labels(Navigation::haupt(), new Konto(1, 'L', Rolle::Lesend)));
        self::assertSame('/app/mitglieder', Navigation::startseiteFuer(new Konto(1, 'L', Rolle::Lesend)));
    }

    public function testTheUserMenuOffersWhatEveryAccountDoesForItself(): void
    {
        $menue = Navigation::benutzermenue(new Konto(1, 'V', Rolle::Vorstand));

        self::assertSame(['Passwort ändern', 'Zweiter Faktor', 'Barrierefrei-Modus einschalten', 'Abmelden'], self::labels($menue));
        self::assertSame(HttpMethod::Post, $menue[2]->methode, 'switching is a write');
        self::assertSame(HttpMethod::Post, $menue[3]->methode, 'logging out is a write');
        self::assertSame('Barrierefrei-Modus ausschalten', Navigation::benutzermenue(new Konto(1, 'V', Rolle::Vorstand, barrierefrei: true))[2]->label);
    }

    public function testBothRolesStartWithTheMembers(): void
    {
        self::assertSame('/app/mitglieder', Navigation::startseiteFuer(new Konto(1, 'A', Rolle::Admin)));
        self::assertSame('/app/mitglieder', Navigation::startseiteFuer(new Konto(1, 'V', Rolle::Vorstand)));
    }

    public function testSubPagesKeepTheirEntryMarked(): void
    {
        $eintrag = new NavItem('Mitglieder', '/app/mitglieder');

        self::assertTrue($eintrag->istAktiv('/app/mitglieder'));
        self::assertTrue($eintrag->istAktiv('/app/mitglieder/7'));
        self::assertFalse($eintrag->istAktiv('/app/mitgliederliste'));
    }
}
