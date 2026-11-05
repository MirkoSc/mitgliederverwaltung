<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Config\Paths;
use App\Http\FileResponse;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\StaticFileHandler;
use App\Service\Verein\LogoService;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TestBilder;

/**
 * The installable app (docs/spec/06-oberflaeche.md section 6): the manifest
 * names the club and uses its logo, the service worker is revalidated and
 * every page links both.
 */
final class PwaTest extends AppTestCase
{
    private string $wurzel;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function paths(): ?Paths
    {
        $this->wurzel = sys_get_temp_dir() . '/mv-pwa-' . bin2hex(random_bytes(4));
        mkdir($this->wurzel . '/shared', 0775, true);
        symlink(dirname(__DIR__, 2), $this->wurzel . '/current');

        return new Paths($this->wurzel . '/current');
    }

    protected function tearDown(): void
    {
        if (isset($this->wurzel)) {
            @unlink($this->wurzel . '/current');
            TestBilder::loeschen($this->wurzel);
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $antwort = $this->get('/manifest.webmanifest');
        self::assertSame(200, $antwort->status);
        self::assertSame('application/manifest+json; charset=utf-8', $antwort->headers['Content-Type']);
        self::assertSame('no-cache', $antwort->headers['Cache-Control']);
        self::assertArrayNotHasKey('Set-Cookie', $antwort->headers);
        $daten = json_decode($antwort->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);

        return $daten;
    }

    public function testTheManifestIsPublicAndWithoutClubDataNamesTheApplication(): void
    {
        $manifest = $this->manifest();

        self::assertSame('Mitgliederverwaltung', $manifest['name']);
        self::assertSame('Mitglieder', $manifest['short_name']);
        self::assertSame('/app', $manifest['start_url']);
        self::assertSame('/', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/app', $manifest['id']);
        self::assertSame('#1e5b45', $manifest['theme_color']);
        self::assertSame(
            [['src' => '/icon.svg?v=0.0.0-test', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']],
            $manifest['icons'],
        );
    }

    public function testTheClubNameAndLogoMakeTheApp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is not available.');
        }
        VereinRepository::aus($this->dienste)->speichern(Vereinsdaten::ausGespeichertem([
            'vereinsname' => 'Turn- und Sportverein Beispielstadt 1888 e. V.',
            'kurzname' => 'TSV Beispielstadt 1888',
        ]));
        LogoService::aus($this->dienste)->speichern(TestBilder::png($this->wurzel));

        $manifest = $this->manifest();

        self::assertSame('Turn- und Sportverein Beispielstadt 1888 e. V.', $manifest['name']);
        self::assertSame('TSV Beispielst…', $manifest['short_name']);
        $icons = $manifest['icons'];
        self::assertIsArray($icons);
        self::assertSame(['any', 'any', 'maskable'], array_column($icons, 'purpose'));
        foreach ($icons as $icon) {
            self::assertMatchesRegularExpression('#^/logo/(icon192|icon512|maskable)\?v=\d+$#', $icon['src']);
            $pfad = (string) parse_url($icon['src'], PHP_URL_PATH);
            $bild = $this->kernel->handle(new Request(HttpMethod::Get, $pfad, query: ['v' => '1']));
            self::assertInstanceOf(FileResponse::class, $bild);
            self::assertSame('image/png', $bild->headers['Content-Type']);
            $info = getimagesize($bild->filePath);
            self::assertNotFalse($info);
            self::assertSame($icon['sizes'], $info[0] . 'x' . $info[1]);
        }
    }

    public function testEveryPageLinksManifestThemeColourAndScripts(): void
    {
        $login = $this->get('/anmelden')->body;

        self::assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $login);
        self::assertStringContainsString('<meta name="theme-color" content="#1e5b45">', $login);
        self::assertStringContainsString('<script src="/js/pwa.js?v=0.0.0-test" defer></script>', $login);
        self::assertStringContainsString('<script src="/js/kopfleiste.js?v=0.0.0-test" defer></script>', $login);
    }

    public function testTheServiceWorkerIsRevalidatedOtherAssetsAreNot(): void
    {
        $handler = new StaticFileHandler(dirname(__DIR__, 2) . '/public', longCache: true);

        $sw = $handler->tryServe(new Request(HttpMethod::Get, '/sw.js'));
        self::assertNotNull($sw);
        self::assertSame('no-cache', $sw->headers['Cache-Control']);
        self::assertSame('text/javascript; charset=utf-8', $sw->headers['Content-Type']);

        $skript = $handler->tryServe(new Request(HttpMethod::Get, '/js/pwa.js'));
        self::assertNotNull($skript);
        self::assertStringContainsString('immutable', $skript->headers['Cache-Control']);
    }
}
