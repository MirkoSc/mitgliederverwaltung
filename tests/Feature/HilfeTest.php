<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Anwendung;
use App\Domain\Rolle;
use App\Service\Hilfe\Anleitungen;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TestBilder;

/**
 * The pages of the help (docs/spec/12-hilfe.md sections 1 and 3,
 * "Pflicht-Tests" of section 7), with the made-up guides under
 * tests/Fixtures/hilfe/gueltig/ instead of the real ones.
 */
final class HilfeTest extends AppTestCase
{
    private string $bilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bilder = sys_get_temp_dir() . '/mv-hilfe-seite-' . bin2hex(random_bytes(4));
        mkdir($this->bilder . '/erste-anleitung', 0o700, true);
        // Step 1 has both pictures; step 3 has "bild" but no file.
        rename(TestBilder::png($this->bilder, 640, 400), $this->bilder . '/erste-anleitung/01-normal.png');
        rename(TestBilder::png($this->bilder, 800, 500), $this->bilder . '/erste-anleitung/01-barrierefrei.png');

        $this->dienste->dienst(Anleitungen::class, fn(): Anleitungen => new Anleitungen(
            dirname(__DIR__) . '/Fixtures/hilfe/gueltig',
            $this->bilder,
            '/img/hilfe',
            '0.0.0-test',
        ));
    }

    protected function tearDown(): void
    {
        TestBilder::loeschen($this->bilder);
        parent::tearDown();
    }

    public function testLoggedOutVisitorsAreSentToTheLogin(): void
    {
        self::assertWeiterleitungNach('/anmelden?weiter=%2Fapp%2Fhilfe', $this->get('/app/hilfe'));
        self::assertWeiterleitungNach('/anmelden?weiter=%2Fapp%2Fhilfe%2Ferste-anleitung', $this->get('/app/hilfe/erste-anleitung'));
    }

    public function testTheOverviewListsOnlyWhatTheRoleMayCarryOut(): void
    {
        $erwartet = [
            'Admin' => [Rolle::Admin, ['erste-anleitung', 'bearbeiten', 'sehen', 'nur-admin']],
            'Vorstand' => [Rolle::Vorstand, ['erste-anleitung', 'bearbeiten', 'sehen']],
            'Nur lesen' => [Rolle::Lesend, ['erste-anleitung', 'sehen']],
        ];

        foreach ($erwartet as $name => [$rolle, $slugs]) {
            $this->als($rolle);
            $antwort = $this->get('/app/hilfe');

            self::assertSame(200, $antwort->status, $name);
            preg_match_all('#<a href="/app/hilfe/([a-z0-9-]+)">#', $antwort->body, $treffer);
            self::assertSame($slugs, $treffer[1], $name);
            self::assertStringContainsString('<h1>Hilfe</h1>', $antwort->body);
            self::assertStringContainsString('Mit der Tastatur bedienen', $antwort->body);
            self::assertStringContainsString('<kbd>Umschalt</kbd> + <kbd>Tab</kbd>', $antwort->body);
        }
    }

    public function testTheUserMenuLinksTheHelp(): void
    {
        $this->als(Rolle::Lesend);

        self::assertStringContainsString('<a href="/app/hilfe">Hilfe</a>', $this->get('/app/konto/passwort')->body);
        self::assertStringContainsString('<a href="/app/hilfe" aria-current="page">Hilfe</a>', $this->get('/app/hilfe/sehen')->body);
    }

    public function testAGuideTheRoleMayNotCarryOutOrThatDoesNotExistIsNotFound(): void
    {
        $this->als(Rolle::Lesend);
        self::assertSame(404, $this->get('/app/hilfe/bearbeiten')->status);
        self::assertSame(404, $this->get('/app/hilfe/nur-admin')->status);
        self::assertSame(404, $this->get('/app/hilfe/gibt-es-nicht')->status);

        $this->als(Rolle::Vorstand);
        self::assertSame(200, $this->get('/app/hilfe/bearbeiten')->status);
        self::assertSame(404, $this->get('/app/hilfe/nur-admin')->status);

        $this->als(Rolle::Admin);
        self::assertSame(200, $this->get('/app/hilfe/nur-admin')->status);
        // Only [a-z0-9-] reaches the controller at all.
        self::assertSame(404, $this->get('/app/hilfe/Erste_Anleitung')->status);
    }

    public function testTheStepsAreAnOrderedListWithEscapedText(): void
    {
        $this->als(Rolle::Vorstand);
        $seite = $this->get('/app/hilfe/erste-anleitung')->body;

        self::assertStringContainsString('<h1>Erste Anleitung</h1>', $seite);
        self::assertStringContainsString('<a href="/app/hilfe">Alle Anleitungen</a>', $seite);
        self::assertMatchesRegularExpression('#<ol class="anleitung">(\s*<li class="anleitung-schritt">.*?</li>){3}\s*</ol>#s', $seite);
        self::assertStringContainsString('Wählen Sie oben in der Leiste <strong>Mitglieder</strong>. &lt;script&gt;alert(1)&lt;/script&gt;', $seite);
        self::assertStringNotContainsString('<script>alert', $seite);
        self::assertMatchesRegularExpression(
            '#<div class="hinweis hinweis-info anleitung-tipp">\s*<p>Tipp: <strong>Mitgliedsnummer</strong> und Nachname genügen\. &lt;b&gt;nicht fett&lt;/b&gt;</p>#',
            $seite,
        );
        self::assertStringNotContainsString('style=', $seite);
    }

    public function testThePictureMatchesTheModeOfTheAccount(): void
    {
        $this->als(Rolle::Vorstand);
        $normal = $this->get('/app/hilfe/erste-anleitung')->body;

        self::assertStringContainsString('src="/img/hilfe/erste-anleitung/01-normal.png?v=0.0.0-test"', $normal);
        self::assertStringContainsString('alt="Die Seite Mitglieder mit dem markierten Knopf „Neues Mitglied&quot;."', $normal);
        self::assertStringContainsString('width="640" height="400"', $normal);
        self::assertStringNotContainsString('-barrierefrei.png', $normal);

        $this->als(Rolle::Vorstand, barrierefrei: true);
        $barrierefrei = $this->get('/app/hilfe/erste-anleitung')->body;

        self::assertStringContainsString('src="/img/hilfe/erste-anleitung/01-barrierefrei.png?v=0.0.0-test"', $barrierefrei);
        self::assertStringContainsString('width="800" height="500"', $barrierefrei);
        self::assertStringNotContainsString('-normal.png', $barrierefrei);
    }

    /** Step 3 has "bild", but there is no file yet: the text carries it alone. */
    public function testAMissingPictureFileLeavesTheImageOut(): void
    {
        $this->als(Rolle::Admin);
        $seite = $this->get('/app/hilfe/erste-anleitung')->body;

        self::assertSame(1, substr_count($seite, 'class="anleitung-bild"'));
        self::assertStringNotContainsString('03-normal.png', $seite);

        self::assertStringNotContainsString('<img class="anleitung-bild"', $this->get('/app/hilfe/sehen')->body);
    }

    public function testTheNextGuideIsOneTheRoleMayCarryOut(): void
    {
        $this->als(Rolle::Lesend);
        self::assertStringContainsString('Nächste Anleitung: <a href="/app/hilfe/sehen">Zum Ansehen</a>', $this->get('/app/hilfe/erste-anleitung')->body);
        self::assertStringNotContainsString('Nächste Anleitung', $this->get('/app/hilfe/sehen')->body);

        $this->als(Rolle::Vorstand);
        self::assertStringContainsString('Nächste Anleitung: <a href="/app/hilfe/bearbeiten">Zum Bearbeiten</a>', $this->get('/app/hilfe/erste-anleitung')->body);
    }

    public function testWithoutGuidesTheOverviewSaysSo(): void
    {
        $this->dienste = self::baueDienste($this->konten);
        $this->dienste->dienst(Anleitungen::class, static fn(): Anleitungen => new Anleitungen('/gibt/es/nicht', '/nirgends'));
        $this->kernel = Anwendung::kernel($this->dienste);
        $this->als(Rolle::Lesend);

        $seite = $this->get('/app/hilfe')->body;
        self::assertStringContainsString('Es gibt noch keine Anleitungen.', $seite);
        self::assertStringContainsString('Mit der Tastatur bedienen', $seite);
    }
}
