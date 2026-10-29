<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Tests\Support\AppTestCase;

/**
 * The layout with its navigation and the placeholder pages of issue #1,
 * as each role sees them (docs/spec/06-oberflaeche.md section 2).
 */
final class SeitenTest extends AppTestCase
{
    /** The settings page reads the mail settings (issue #7). */
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        self::assertWeiterleitungNach('/app', $this->get('/'));
        self::assertWeiterleitungNach('/anmelden?weiter=%2Fapp', $this->get('/app'));
        self::assertWeiterleitungNach('/anmelden?weiter=%2Fapp%2Fmitglieder', $this->get('/app/mitglieder'));
    }

    public function testBothRolesStartWithTheMembers(): void
    {
        $this->als(Rolle::Vorstand);
        self::assertWeiterleitungNach('/app/mitglieder', $this->get('/app'));

        $this->als(Rolle::Admin);
        self::assertWeiterleitungNach('/app/mitglieder', $this->get('/app'));
    }

    public function testTheAdminSeesTheWholeNavigation(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->get('/app/mitglieder');

        self::assertSame(200, $seite->status);
        foreach (['/app/mitglieder', '/app/listen', '/app/beitraege', '/admin'] as $ziel) {
            self::assertStringContainsString('href="' . $ziel . '"', $seite->body);
        }
        self::assertStringContainsString('<a href="/app/mitglieder" aria-current="page">Mitglieder</a>', $seite->body);
        self::assertStringContainsString('Testzugang Admin', $seite->body);
    }

    /** 06 section 2: Beiträge and Einstellungen are not even shown to the board. */
    public function testTheBoardSeesNeitherFeesNorSettings(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/listen');

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('href="/app/mitglieder"', $seite->body);
        self::assertStringNotContainsString('Beiträge', $seite->body);
        self::assertStringNotContainsString('href="/admin"', $seite->body);
        // ... and the server refuses them as well.
        self::assertSame(403, $this->get('/app/beitraege')->status);
        self::assertSame(403, $this->get('/admin')->status);
    }

    /**
     * The user menu is complete; what is not built yet shows as such instead
     * of leading to a 404, and lights up once its route exists.
     */
    public function testTheUserMenu(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->get('/app/mitglieder')->body;

        self::assertStringContainsString('<a href="/app/konto/passwort">Passwort ändern</a>', $seite);
        self::assertStringContainsString('<a href="/app/konto/zweiter-faktor">Zweiter Faktor</a>', $seite);
        self::assertStringContainsString('Barrierefrei-Modus einschalten</button>', $seite);
        self::assertMatchesRegularExpression('#<form method="post" action="/abmelden">\s*<input type="hidden" name="_csrf" value="[0-9a-f]{64}">#', $seite);
    }

    public function testThePageCarriesTheTokenForHtmxAndTheConfiguredHtmx(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->get('/app/mitglieder')->body;

        self::assertMatchesRegularExpression('/<body class="bereich-app" hx-headers=\'\{"X-CSRF-Token":"[0-9a-f]{64}"\}\' data-leerlauf="1800" data-weiter="\/app\/konto\/weiterarbeiten">/', $seite);
        self::assertStringContainsString('<link rel="stylesheet" href="/css/app.css?v=0.0.0-test">', $seite);
        self::assertStringContainsString('<script src="/js/vendor/htmx.min.js?v=0.0.0-test" defer></script>', $seite);
        self::assertStringContainsString('<script src="/js/benutzermenue.js?v=0.0.0-test" defer></script>', $seite);
        self::assertStringContainsString('<a class="sprungmarke" href="#inhalt">', $seite);
    }

    /** 06 section 4: one class on <html>, from the account's setting. */
    public function testTheBarrierefreiModusComesFromTheAccount(): void
    {
        $this->als(Rolle::Vorstand);
        self::assertStringContainsString('<html lang="de">', $this->get('/app/mitglieder')->body);

        $this->als(Rolle::Vorstand, barrierefrei: true);
        $seite = $this->get('/app/mitglieder')->body;
        self::assertStringContainsString('<html lang="de" class="modus-barrierefrei">', $seite);
        self::assertStringContainsString('Barrierefrei-Modus ausschalten', $seite);
    }

    public function testThePlaceholdersSayWhatWillBeThere(): void
    {
        $this->als(Rolle::Admin);

        self::assertStringContainsString('<h1>Mitglieder</h1>', $this->get('/app/mitglieder')->body);
        self::assertStringContainsString('<h1>Beiträge</h1>', $this->get('/app/beitraege')->body);
        self::assertStringContainsString('Noch ist keine eingerichtet.', $this->get('/app/listen')->body);
        self::assertStringContainsString('<h1>Einstellungen</h1>', $this->get('/admin')->body);
    }

    public function testAnUnknownListIsA404(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->get('/app/listen/gibt-es-nicht');

        self::assertSame(404, $seite->status);
        self::assertStringContainsString('Liste nicht gefunden', $seite->body);
    }

    public function testThePublicPagesHaveNoNavigationAndNoToken(): void
    {
        $seite = $this->get('/anmelden')->body;

        self::assertStringNotContainsString('Hauptnavigation', $seite);
        self::assertStringNotContainsString('hx-headers', $seite);
        self::assertStringContainsString('<body class="bereich-oeffentlich">', $seite);
    }

    public function testLoggingOutEndsTheSession(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(403, $this->post('/abmelden', mitToken: false)->status, 'not without a token');
        self::assertWeiterleitungNach('/anmelden', $this->post('/abmelden'));
        self::assertWeiterleitungNach('/anmelden?weiter=%2Fapp%2Fmitglieder', $this->get('/app/mitglieder'));
    }

    public function testTheFooterShowsTheVersion(): void
    {
        $this->als(Rolle::Admin);

        self::assertStringContainsString('Version 0.0.0-test', $this->get('/app/mitglieder')->body);
    }
}
