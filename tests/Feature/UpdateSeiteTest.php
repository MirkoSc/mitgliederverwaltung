<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Http\Response;
use App\Repository\SettingRepository;
use App\Service\MaintenanceMode;
use App\Service\Migration\Migrator;
use App\Service\Update\ReleaseDownloader;
use App\Service\Update\ReleaseSwitcher;
use App\Service\Update\UpdateService;
use App\Tests\Support\AppTestCase;

/**
 * The settings section Update and the maintenance banner through the whole
 * application (04 sections 3 and 4): the section with its script, the step
 * route as the page's script calls it, the banner on every admin page while
 * the flag is set, and its release button. The flag lives in a temporary
 * directory, the downloader answers from memory - nothing leaves the machine.
 */
final class UpdateSeiteTest extends AppTestCase
{
    private string $root;

    private MaintenanceMode $wartung;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mv_updateseite_' . uniqid('', true);
        mkdir($this->root . '/shared', 0775, true);
        $this->wartung = new MaintenanceMode($this->root . '/shared/maintenance.flag');
        $this->dienste->dienst(MaintenanceMode::class, fn(): MaintenanceMode => $this->wartung);

        // No release on GitHub: the version check answers "up to date".
        $this->dienste->dienst(UpdateService::class, fn(): UpdateService => new UpdateService(
            paths: new \App\Config\Paths($this->root . '/current'),
            currentVersion: '0.0.0-test',
            settings: new SettingRepository($this->dienste->pdo()),
            downloader: new ReleaseDownloader(static fn(string $url): string => throw new \RuntimeException('HTTP 404 für ' . $url)),
            switcher: new ReleaseSwitcher($this->root, $this->wartung),
            migrator: new Migrator($this->dienste->pdo(), dirname(__DIR__, 2) . '/migrations'),
            backups: \App\Service\Backup\BackupErstellung::aus($this->dienste),
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/shared/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->root . '/shared/var/update/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach ([$this->root . '/shared/var/update', $this->root . '/shared/var', $this->root . '/shared', $this->root] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    public function testTheSettingsPageShowsTheSectionWithItsScript(): void
    {
        $this->als(Rolle::Admin);

        $seite = $this->get('/admin')->body;

        self::assertStringContainsString('<h2 id="titel-update" tabindex="-1">Update</h2>', $seite);
        self::assertStringContainsString('id="update-suchen"', $seite);
        self::assertStringContainsString('data-schritte="check,download,extract,backup,switch,migrate,finish"', $seite);
        self::assertStringContainsString('<script src="/js/update.js?v=0.0.0-test" defer></script>', $seite);
        // no chain started, no previous release: nothing to resume or roll back
        self::assertStringNotContainsString('id="update-fortsetzen"', $seite);
        self::assertStringNotContainsString('id="update-rollback"', $seite);
    }

    public function testTheVersionCheckAnswersTheStateAsJson(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/update/schritt/check');

        self::assertSame(200, $antwort->status);
        $stand = $this->json($antwort);
        self::assertTrue($stand['fertig']);
        self::assertSame('Kein Release gefunden.', $stand['meldungen'][0]);
        self::assertStringContainsString('Letzter Stand', $this->get('/admin')->body);
    }

    public function testAStepWithoutAPreparedUpdateAnswersAnError(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/update/schritt/download');

        self::assertSame(500, $antwort->status);
        self::assertStringContainsString('Kein Update vorbereitet', $this->json($antwort)['fehler']);
    }

    public function testAnUnknownStepIsNotFound(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(404, $this->post('/admin/update/schritt/nachbessern')->status);
    }

    public function testAStepNeedsTheCsrfToken(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(403, $this->post('/admin/update/schritt/check', mitToken: false)->status);
    }

    public function testTheChannelIsSavedAndTheStateReset(): void
    {
        $this->als(Rolle::Admin);
        $this->post('/admin/update/schritt/check');

        $antwort = $this->post('/admin/update/kanal', ['kanal' => 'beta']);

        self::assertWeiterleitungNach('/admin#update', $antwort);
        self::assertSame('beta', new SettingRepository($this->dienste->pdo())->get('update_kanal'));
        self::assertStringNotContainsString('Letzter Stand', $this->get('/admin')->body, 'the state of the old channel is gone');
        self::assertStringContainsString('<option value="beta" selected>', $this->get('/admin')->body);
    }

    public function testNoBannerWithoutTheFlag(): void
    {
        $this->als(Rolle::Admin);

        self::assertStringNotContainsString('Wartungsmodus ist aktiv', $this->get('/admin')->body);
    }

    public function testTheBannerShowsOnEveryPageWhileTheFlagIsSet(): void
    {
        $this->als(Rolle::Admin);
        $this->wartung->enable('Update');

        foreach (['/admin', '/app/mitglieder', '/app/listen'] as $pfad) {
            $seite = $this->get($pfad)->body;
            self::assertStringContainsString('Wartungsmodus ist aktiv', $seite, $pfad);
            self::assertStringContainsString('Grund: Update, seit ', $seite, $pfad);
            self::assertStringContainsString('action="/admin/wartung/aufheben"', $seite, $pfad);
        }
    }

    public function testTheBoardOnlyGetsTheBannerNotTheButton(): void
    {
        $this->als(Rolle::Vorstand);
        $this->wartung->enable('Update');

        self::assertStringNotContainsString('Wartungsmodus ist aktiv', $this->get('/app/mitglieder')->body);
    }

    public function testReleasingTheFlagLeavesAnAuditRowAndAMessage(): void
    {
        $this->als(Rolle::Admin);
        $this->wartung->enable('Update');

        $antwort = $this->post('/admin/wartung/aufheben');

        self::assertWeiterleitungNach('/admin#update', $antwort);
        self::assertFalse($this->wartung->isActive());
        $seite = $this->get('/admin')->body;
        self::assertStringContainsString('Der Wartungsmodus ist aufgehoben', $seite);
        self::assertStringNotContainsString('Wartungsmodus ist aktiv', $seite);
        self::assertSame(
            1,
            (int) $this->dienste->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'wartung.aufgehoben'")->fetchColumn(),
        );
    }

    public function testReleasingTheFlagNeedsTheTokenAndTheRight(): void
    {
        $this->wartung->enable('Update');

        $this->als(Rolle::Admin);
        self::assertSame(403, $this->post('/admin/wartung/aufheben', mitToken: false)->status);
        self::assertTrue($this->wartung->isActive());

        $this->als(Rolle::Vorstand);
        self::assertSame(403, $this->post('/admin/wartung/aufheben')->status);
        self::assertTrue($this->wartung->isActive());
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $antwort): array
    {
        $daten = json_decode($antwort->body, true);
        self::assertIsArray($daten);

        return $daten;
    }
}
