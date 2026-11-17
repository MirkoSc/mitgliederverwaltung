<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Dienste;
use App\Http\Response;
use App\Service\Verein\LogoService;

/**
 * The web app manifest (docs/spec/06-oberflaeche.md section 6): makes the
 * application installable as an app on the phone and the PC, always named
 * "Mitgliederverwaltung" ("Mitglieder" under the icon) and with the club's
 * logo as the icon.
 *
 * Public: a browser fetches the manifest without cookies. It carries only
 * the fixed names and the logo URLs - the logo stands on the login page
 * anyway.
 */
final readonly class ManifestController
{
    public const string PFAD = '/manifest.webmanifest';

    /** Colours of the design system (public/css/app.css, light). */
    public const string THEME_FARBE = '#1e5b45';

    private const string HINTERGRUND = '#f1f4f1';

    /** The installed app's name, also in the layout (application-name). */
    public const string NAME = 'Mitgliederverwaltung';

    /**
     * The label under the icon, also in the layout (apple-mobile-web-app-
     * title): launchers cut anything longer than about 12 characters.
     */
    public const string KURZNAME = 'Mitglieder';

    public function __construct(private Dienste $dienste)
    {
    }

    public function anzeigen(): Response
    {
        try {
            $logo = LogoService::aus($this->dienste)->version();
        } catch (\Throwable) {
            // no database (yet): the application icon, never an error
            $logo = null;
        }

        $manifest = [
            'id' => '/app',
            'name' => self::NAME,
            'short_name' => self::KURZNAME,
            'description' => self::NAME,
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
