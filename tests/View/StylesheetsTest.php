<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\Stylesheets;
use PHPUnit\Framework\TestCase;

/**
 * The CSS extension point (docs/spec/05-entwicklung.md section 6): a file in
 * public/css/teile/ is loaded after the design system, without a change to
 * the layout.
 */
final class StylesheetsTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/mv-css-' . uniqid('', true);
        mkdir($this->public . '/css/teile', 0777, true);
        touch($this->public . '/css/app.css');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->public . '/css/teile/*') ?: [] as $datei) {
            unlink($datei);
        }
        rmdir($this->public . '/css/teile');
        unlink($this->public . '/css/app.css');
        rmdir($this->public . '/css');
        rmdir($this->public);
    }

    public function testTheDesignSystemComesFirstThenEveryPartInNameOrder(): void
    {
        touch($this->public . '/css/teile/mitglieder.css');
        touch($this->public . '/css/teile/beitraege.css');
        touch($this->public . '/css/teile/README.md');

        self::assertSame(
            ['/css/app.css', '/css/teile/beitraege.css', '/css/teile/mitglieder.css'],
            Stylesheets::aus($this->public),
        );
    }

    public function testWithoutPartsOnlyTheDesignSystem(): void
    {
        self::assertSame(['/css/app.css'], Stylesheets::aus($this->public));
    }

    public function testTheRealDesignSystemExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/public' . Stylesheets::DESIGNSYSTEM);
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/public' . Stylesheets::TEILE);
    }

    /**
     * Light and dark, and the Barrierefrei-Modus as a class that only
     * overrides tokens (docs/spec/06-oberflaeche.md section 4).
     */
    public function testTheDesignSystemHasBothSchemesAndTheBarrierefreiModus(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/app.css');

        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertStringContainsString(':root.modus-barrierefrei', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
    }
}
