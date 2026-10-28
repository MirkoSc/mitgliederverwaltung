<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Tests\Support\AppTestCase;

/**
 * Impressum and Datenschutz (issue #14, docs/spec/02-sicherheit.md section 6):
 * public pages, edited by the admin only, escaped on output.
 */
final class RechtstexteTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->datenbank();
    }

    protected function pdoFabrik(): ?\Closure
    {
        return fn(): \PDO => $this->datenbank();
    }

    public function testBothPagesAreReachableWithoutLogin(): void
    {
        $impressum = $this->get('/impressum');
        $datenschutz = $this->get('/datenschutz');

        self::assertSame(200, $impressum->status);
        self::assertSame(200, $datenschutz->status);
        self::assertStringContainsString('<h1>Impressum</h1>', $impressum->body);
        self::assertStringContainsString('<h1>Datenschutz</h1>', $datenschutz->body);
    }

    public function testAnEmptyTextSaysSo(): void
    {
        self::assertStringContainsString('noch nicht eingetragen', $this->get('/impressum')->body);
    }

    public function testTheAdminEntersTheTextAndTheSitePrintsItEscaped(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/rechtstexte/impressum', ['text' => "# Verein\n\n- Vorsitz: <b>Mustermann</b>\n[Web](https://example.org)"]);

        self::assertWeiterleitungNach('/admin#rechtstexte', $antwort);
        $this->abmelden();

        $seite = $this->get('/impressum')->body;
        self::assertStringContainsString('<h2>Verein</h2>', $seite);
        self::assertStringContainsString('<li>Vorsitz: &lt;b&gt;Mustermann&lt;/b&gt;</li>', $seite);
        self::assertStringContainsString('<a href="https://example.org">Web</a>', $seite);
        self::assertStringNotContainsString('<b>Mustermann', $seite);
    }

    public function testTheBoardCannotChangeTheText(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->post('/admin/rechtstexte/datenschutz', ['text' => 'Neu'])->status);
    }

    public function testAPostWithoutTokenIsRefused(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(403, $this->post('/admin/rechtstexte/datenschutz', ['text' => 'Neu'], mitToken: false)->status);
    }

    public function testAnOverlongTextIsRefusedNotCutOff(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/rechtstexte/datenschutz', ['text' => str_repeat('a', 20001)]);

        self::assertWeiterleitungNach('/admin#rechtstexte', $antwort);
        $this->abmelden();
        self::assertStringContainsString('noch nicht eingetragen', $this->get('/datenschutz')->body);
    }

    public function testTheSettingsPageOffersBothTexts(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->get('/admin')->body;

        self::assertStringContainsString('id="rechtstexte"', $seite);
        self::assertStringContainsString('action="/admin/rechtstexte/impressum"', $seite);
        self::assertStringContainsString('action="/admin/rechtstexte/datenschutz"', $seite);
    }
}
