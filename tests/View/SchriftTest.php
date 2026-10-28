<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\TestCase;

/**
 * The vendored typeface (public/css/schrift/): unchanged files, a recorded
 * licence, and an @font-face in the design system that points at them -
 * the same guard as tests/View/VendorAssetsTest.php for the JS libraries.
 */
final class SchriftTest extends TestCase
{
    private const string ORDNER = '/public/css/schrift';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function schriftdateien(): array
    {
        $dateien = array_map(basename(...), glob(self::repoRoot() . self::ORDNER . '/*.woff2') ?: []);
        sort($dateien);

        return $dateien;
    }

    public function testEveryFontFileIsDocumentedWithItsChecksum(): void
    {
        $readme = (string) file_get_contents(self::repoRoot() . self::ORDNER . '/README.md');

        self::assertNotSame([], self::schriftdateien());
        foreach (self::schriftdateien() as $datei) {
            self::assertStringContainsString($datei, $readme, $datei . ' is not documented');
            self::assertStringContainsString(
                (string) hash_file('sha256', self::repoRoot() . self::ORDNER . '/' . $datei),
                $readme,
                $datei . ' differs from the reviewed version recorded in the README',
            );
        }
    }

    public function testTheLicenceTravelsWithTheFont(): void
    {
        self::assertStringContainsString(
            'SIL OPEN FONT LICENSE Version 1.1',
            (string) file_get_contents(self::repoRoot() . self::ORDNER . '/OFL.txt'),
        );
    }

    /** Every file the stylesheet names exists, and every file is used. */
    public function testTheDesignSystemLoadsExactlyTheseFiles(): void
    {
        $css = (string) file_get_contents(self::repoRoot() . '/public/css/app.css');
        preg_match_all('#url\("schrift/([^"]+)"\)#', $css, $treffer);
        $genannt = $treffer[1];
        sort($genannt);

        self::assertSame(self::schriftdateien(), $genannt);
    }

    /** font-src 'self': a font from another host would be blocked anyway. */
    public function testTheStylesheetLoadsNothingFromAnotherHost(): void
    {
        $css = (string) file_get_contents(self::repoRoot() . '/public/css/app.css');

        self::assertDoesNotMatchRegularExpression('#url\(\s*["\x27]?(?:https?:)?//#i', $css);
        self::assertStringNotContainsString('@import', $css);
    }
}
