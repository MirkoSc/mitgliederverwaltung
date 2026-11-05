<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Dienste;
use App\Http\Response;
use App\Service\Verein\LogoService;
use App\Service\Verein\VereinRepository;

/**
 * The web app manifest (docs/spec/06-oberflaeche.md section 6): makes the
 * application installable as an app on the phone and the PC, named after
 * the club and with its logo as the icon.
 *
 * Public: a browser fetches the manifest without cookies. It carries only
 * the club's name and the logo URLs - both stand on the login page anyway.
 */
final readonly class ManifestController
{
    public const string PFAD = '/manifest.webmanifest';

    /** Colours of the design system (public/css/app.css, light). */
    public const string THEME_FARBE = '#1e5b45';

    private const string HINTERGRUND = '#f1f4f1';

    /** Launchers cut a longer short_name themselves, often mid-word. */
    private const int KURZ_MAX = 15;

    public function __construct(private Dienste $dienste)
    {
    }

    public function anzeigen(): Response
    {
        try {
            $verein = VereinRepository::aus($this->dienste)->get();
            $name = $verein->wert('vereinsname');
            $kurz = $verein->anzeigename();
            $logo = LogoService::aus($this->dienste)->version();
        } catch (\Throwable) {
            // no database (yet): the plain application, never an error
            $name = $kurz = '';
            $logo = null;
        }
        $name = $name !== '' ? $name : ($kurz !== '' ? $kurz : 'Mitgliederverwaltung');
        $kurz = $kurz !== '' ? $kurz : 'Mitglieder';
        if (mb_strlen($kurz) > self::KURZ_MAX) {
            $kurz = rtrim(mb_substr($kurz, 0, self::KURZ_MAX - 1)) . '…';
        }

        $manifest = [
            'id' => '/app',
            'name' => $name,
            'short_name' => $kurz,
            'description' => 'Mitgliederverwaltung',
            'lang' => 'de',
            'dir' => 'ltr',
            'start_url' => '/app',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => self::THEME_FARBE,
            'background_color' => self::HINTERGRUND,
            'icons' => self::icons($logo, $this->dienste->version->value),
        ];

        return new Response(
            200,
            ['Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'no-cache'],
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * With a logo its PNG sizes (App\Service\Verein\LogoService), without
     * one the application icon, which as an SVG fits every size.
     *
     * @return list<array<string, string>>
     */
    private static function icons(?int $logo, string $version): array
    {
        if ($logo === null) {
            return [['src' => '/icon.svg?v=' . $version, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']];
        }

        return [
            ['src' => '/logo/icon192?v=' . $logo, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/logo/icon512?v=' . $logo, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/logo/maskable?v=' . $logo, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }
}
