<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Tests\Support\AppTestCase;
use App\View\View;

/**
 * The pattern page /admin/designsystem and the parts of the layout issue #9
 * owns: logo placeholder and Barrierefrei-Modus (docs/spec/06-oberflaeche.md).
 */
final class DesignsystemTest extends AppTestCase
{
    private const string SEITE = '/admin/designsystem';

    public function testOnlyTheAdminOpensThePatternPage(): void
    {
        self::assertWeiterleitungNach('/anmelden?weiter=%2Fadmin%2Fdesignsystem', $this->get(self::SEITE));

        $this->als(Rolle::Vorstand);
        self::assertSame(403, $this->get(self::SEITE)->status);

        $this->als(Rolle::Admin);
        self::assertSame(200, $this->get(self::SEITE)->status);
    }

    /** Acceptance criterion: the page shows every building block. */
    public function testThePatternPageShowsEveryBuildingBlock(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->get(self::SEITE)->body;

        foreach ([
            'muster-farben', 'knopf knopf-haupt', 'knopf knopf-gefahr', 'knopf knopf-still',
            'hinweis hinweis-ok', 'hinweis hinweis-info', 'hinweis hinweis-warnung', 'hinweis hinweis-fehler',
            'etikett etikett-ok', 'formular formular-zweispaltig', 'feld-fehler', 'feld-hinweis', 'feld-haken',
            'formular-aktionen', 'class="suche"', 'class="filter"', 'class="liste"', 'aria-sort="ascending"',
            'data-label="Nr."', 'class="blaettern"', 'class="leer"', 'class="geteilt"', 'class="abschnitt"',
            'class="auswahl"', 'htmx-indicator', 'class="laedt',
        ] as $baustein) {
            self::assertStringContainsString($baustein, $seite, $baustein);
        }
    }

    /**
     * Every class the pattern page uses exists in the design system - the
     * page is the reference other issues copy from, so it must not show a
     * class that styles nothing.
     */
    public function testEveryClassOfThePatternPageIsDefinedInTheDesignSystem(): void
    {
        $vorlage = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/admin/designsystem.php');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css');
        preg_match_all('/class="([^"<]+)"/', $vorlage, $treffer);

        $fehlend = [];
        foreach ($treffer[1] as $klassen) {
            foreach (preg_split('/\s+/', trim($klassen)) ?: [] as $klasse) {
                if (preg_match('/\.' . preg_quote($klasse, '/') . '(?![\w-])/', $css) !== 1) {
                    $fehlend[] = $klasse;
                }
            }
        }

        self::assertSame([], array_values(array_unique($fehlend)));
    }

    /**
     * The member opens next to the list: the server marks it, the list
     * marks the row, and the tabs are links with aria-current.
     */
    public function testAnOpenedMemberStandsNextToTheList(): void
    {
        $this->als(Rolle::Admin);

        $ohne = $this->get(self::SEITE)->body;
        self::assertStringContainsString('<div class="geteilt">', $ohne);
        self::assertStringNotContainsString('class="detail-kopf"', $ohne, 'without a member the list has the whole width');

        $mit = $this->get(self::SEITE, ['mitglied' => '3', 'reiter' => 'beitraege'])->body;
        self::assertStringContainsString('<div class="geteilt geteilt-mit-detail">', $mit);
        self::assertStringContainsString('aria-current="true">Beispiel, Karl</a>', $mit);
        self::assertStringContainsString('Zurück zur Liste', $mit);
        self::assertStringContainsString('detail-schliessen" href="/admin/designsystem#muster-geteilt">Schließen</a>', $mit);
        self::assertMatchesRegularExpression('#reiter=beitraege\#muster-geteilt" aria-current="page">Beiträge</a>#', $mit);
        self::assertStringContainsString('Beitragsart Rentner, aber erst 64 Jahre alt.', $mit);

        // Unknown values fall back instead of failing.
        $unbekannt = $this->get(self::SEITE, ['mitglied' => '99', 'reiter' => 'x'])->body;
        self::assertStringContainsString('<div class="geteilt">', $unbekannt);
    }

    /**
     * The preview shows the page in the Barrierefrei-Modus and keeps it on
     * every example link - without touching the account's setting.
     */
    public function testThePreviewSwitchesTheModeForThisViewOnly(): void
    {
        $this->als(Rolle::Admin);

        $normal = $this->get(self::SEITE)->body;
        self::assertStringContainsString('<html lang="de">', $normal);
        self::assertStringContainsString('Im Barrierefrei-Modus ansehen', $normal);

        $vorschau = $this->get(self::SEITE, ['barrierefrei' => '1'])->body;
        self::assertStringContainsString('<html lang="de" class="modus-barrierefrei">', $vorschau);
        self::assertStringContainsString('href="/admin/designsystem?mitglied=1&amp;barrierefrei=1#muster-geteilt"', $vorschau);
        // The account still has the mode off - the menu offers to switch it on.
        self::assertStringContainsString('Barrierefrei-Modus einschalten', $vorschau);
        // And no other page takes the parameter.
        self::assertStringContainsString('<html lang="de">', $this->get('/app/listen', ['barrierefrei' => '1'])->body);
    }

    /**
     * 06 "Pflicht-Tests": the mode comes from the account's setting and
     * survives reloading - every request reads it again, no state in the
     * browser.
     */
    public function testTheModeFromTheAccountSurvivesReloading(): void
    {
        $this->als(Rolle::Vorstand, barrierefrei: true);

        self::assertStringContainsString('<html lang="de" class="modus-barrierefrei">', $this->get('/app/listen')->body);
    }

    /** 11 section 2: without an uploaded logo the header shows a placeholder. */
    public function testTheHeaderShowsTheLogoPlaceholderOnEveryPage(): void
    {
        $logo = '<img class="marke-logo" src="' . View::LOGO_PLATZHALTER . '?v=0.0.0-test" alt="" width="40" height="40">';

        self::assertStringContainsString($logo, $this->get('/anmelden')->body);

        $this->als(Rolle::Vorstand);
        self::assertStringContainsString($logo, $this->get('/app/listen')->body);
        self::assertFileExists(dirname(__DIR__, 2) . '/public' . View::LOGO_PLATZHALTER);
    }

    public function testAnUploadedLogoReplacesThePlaceholder(): void
    {
        $this->dienste->view->setLogo('/logo?v=123');
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/listen')->body;

        self::assertStringContainsString('<img class="marke-logo" src="/logo?v=123" alt=""', $seite);
        self::assertStringNotContainsString(View::LOGO_PLATZHALTER, $seite);
    }
}
