<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Tests\Support\AppTestCase;

/**
 * End to end through the real directories (docs/spec/05-entwicklung.md
 * section 6): a settings section or a list dropped into its directory shows
 * up on its page - no route, no template and no list anywhere else changed.
 *
 * The files are written for the test and removed afterwards - in tearDown,
 * and by a shutdown function should the run die on the way. Should one be
 * left behind all the same, it cannot do harm: .gitignore keeps
 * `Testlauf*.php` out of every commit (and so out of every release), and
 * each carries a key of its own, so two of them never collide.
 */
final class ErweiterungsSeitenTest extends AppTestCase
{
    /** The settings page reads the mail settings (issue #7). */
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    /** @var list<string> */
    private array $angelegt = [];

    protected function tearDown(): void
    {
        foreach ($this->angelegt as $datei) {
            @unlink($datei);
        }
        parent::tearDown();
    }

    private function anlegen(string $verzeichnis, string $klasse, string $code): void
    {
        $datei = dirname(__DIR__, 2) . '/app/src/' . $verzeichnis . '/' . $klasse . '.php';
        file_put_contents($datei, $code);
        $this->angelegt[] = $datei;
        register_shutdown_function(static function () use ($datei): void {
            if (is_file($datei)) {
                unlink($datei);
            }
        });
    }

    /** Class name prefix and key of one test run. */
    private static function kennung(): string
    {
        return bin2hex(random_bytes(4));
    }

    public function testANewSettingsSectionAppearsOnTheSettingsPage(): void
    {
        $kennung = self::kennung();
        $klasse = 'Testlauf' . $kennung . 'Abschnitt';
        $this->anlegen('Admin/Einstellungen', $klasse, <<<PHP
            <?php
            declare(strict_types=1);
            namespace App\\Admin\\Einstellungen;
            use App\\Dienste;
            use App\\Domain\\Permission;
            final class {$klasse} implements Abschnitt
            {
                public static function erzeugen(Dienste \$dienste): self { return new self(); }
                public function schluessel(): string { return 'testlauf-{$kennung}'; }
                public function titel(): string { return 'Testlauf-Abschnitt'; }
                public function reihenfolge(): int { return 1; }
                public function recht(): Permission { return Permission::AdminEinstellungen; }
                public function template(): string { return 'platzhalter'; }
                public function daten(): array { return ['title' => 'Innen', 'text' => 'Inhalt aus dem Abschnitt.']; }
            }
            PHP);
        $this->als(Rolle::Admin);

        $seite = $this->get('/admin')->body;

        self::assertStringContainsString('<section class="abschnitt" id="testlauf-' . $kennung . '" aria-labelledby="titel-testlauf-' . $kennung . '">', $seite);
        self::assertStringContainsString('<h2 id="titel-testlauf-' . $kennung . '">Testlauf-Abschnitt</h2>', $seite);
        self::assertStringContainsString('Inhalt aus dem Abschnitt.', $seite);
    }

    public function testANewListAppearsOnTheListsPageAndHasItsOwnAddress(): void
    {
        $kennung = self::kennung();
        $klasse = 'Testlauf' . $kennung . 'Liste';
        $this->anlegen('Listen', $klasse, <<<PHP
            <?php
            declare(strict_types=1);
            namespace App\\Listen;
            use App\\Dienste;
            use App\\Domain\\Konto;
            use App\\Domain\\Permission;
            final class {$klasse} implements Liste
            {
                public function filter(): array { return []; }
                public function ergebnis(array \$filter, Konto \$konto): ListenErgebnis { return new ListenErgebnis([Spalte::text('name', 'Name')], []); }
                public function dateiname(array \$filter): string { return 'testlauf'; }
                public static function erzeugen(Dienste \$dienste): self { return new self(); }
                public function schluessel(): string { return 'testlauf-{$kennung}'; }
                public function titel(): string { return 'Testlauf-Liste'; }
                public function beschreibung(): string { return 'Zeigt, dass Listen gefunden werden.'; }
                public function reihenfolge(): int { return 1; }
                public function recht(): Permission { return Permission::BeitraegeVerwalten; }
            }
            PHP);

        $this->als(Rolle::Admin);
        self::assertStringContainsString('<a href="/app/listen/testlauf-' . $kennung . '">Testlauf-Liste</a>', $this->get('/app/listen')->body);
        self::assertStringContainsString('Zeigt, dass Listen gefunden werden.', $this->get('/app/listen/testlauf-' . $kennung)->body);

        // A list with fee data does not exist for the board (09).
        $this->als(Rolle::Vorstand);
        self::assertStringNotContainsString('Testlauf-Liste', $this->get('/app/listen')->body);
        self::assertSame(404, $this->get('/app/listen/testlauf-' . $kennung)->status);
    }
}
