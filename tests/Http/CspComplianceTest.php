<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Content-Security-Policy the docroot .htaccess sends
 * (CLAUDE.md section 4, docs/spec/02-sicherheit.md section 7). Taken over
 * from Vereinsbelege.
 *
 * The policy restricts scripts and styles to same-origin files, which only
 * holds as long as no template reintroduces an inline <script>, an inline
 * event handler or an inline style. That failure mode is the reason this is
 * a test rather than a review item: a browser enforcing the policy silently
 * DROPS them. The page keeps rendering, the button keeps looking clickable -
 * nothing in the application would notice.
 */
final class CspComplianceTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function viewFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::repoRoot() . '/app/views', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string> own scripts, without the vendored libraries
     */
    private static function ownScripts(): array
    {
        $files = glob(self::repoRoot() . '/public/js/*.js') ?: [];
        sort($files);

        return $files;
    }

    private static function htaccess(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/docker/web/.htaccess');
    }

    private static function csp(): string
    {
        preg_match('/Content-Security-Policy "([^"]+)"/', self::htaccess(), $m);
        self::assertNotEmpty($m, 'no Content-Security-Policy header in docker/web/.htaccess');

        return $m[1];
    }

    private static function direktive(string $name): string
    {
        preg_match('/(?:^|; )' . preg_quote($name, '/') . ' ([^;]+)/', self::csp(), $m);
        self::assertNotEmpty($m, 'no ' . $name . ' directive');

        return $m[1];
    }

    public function testThereAreViewsToCheck(): void
    {
        self::assertNotSame([], self::viewFiles(), 'the scan below would pass vacuously');
    }

    /**
     * Inline handlers (onclick, onsubmit, ...) are dead under script-src.
     * htmx's hx-on is the same thing in a different spelling.
     */
    public function testNoViewUsesAnInlineEventHandler(): void
    {
        $treffer = [];
        foreach (self::viewFiles() as $file) {
            $inhalt = (string) file_get_contents($file);
            if (preg_match_all('/\son[a-z]+\s*=\s*["\x27]/i', $inhalt, $m) > 0) {
                $treffer[] = basename($file) . ': ' . implode(', ', $m[0]);
            }
            if (preg_match_all('/\shx-on[:a-z-]*\s*=\s*["\x27]/i', $inhalt, $m) > 0) {
                $treffer[] = basename($file) . ': ' . implode(', ', $m[0]);
            }
        }

        self::assertSame([], $treffer, "Inline event handlers are silently disabled by script-src.\nUse a listener in public/js/ instead.");
    }

    /**
     * <script> with a body is blocked too. type="application/json" is fine -
     * it is data the page reads, never executed.
     */
    public function testNoViewEmbedsAnExecutableInlineScript(): void
    {
        $treffer = [];
        foreach (self::viewFiles() as $file) {
            preg_match_all('/<script(?<attrs>[^>]*)>/i', (string) file_get_contents($file), $m);
            foreach ($m['attrs'] as $attrs) {
                if (str_contains($attrs, 'src=') || str_contains($attrs, 'application/json')) {
                    continue;
                }
                $treffer[] = basename($file);
            }
        }

        self::assertSame([], $treffer, "Inline <script> is blocked by script-src 'self'. Move it to public/js/.");
    }

    /**
     * style-src 'self' without 'unsafe-inline' drops style attributes and
     * <style> blocks as well - layout belongs into public/css/.
     */
    public function testNoViewUsesInlineStyles(): void
    {
        $treffer = [];
        foreach (self::viewFiles() as $file) {
            $inhalt = (string) file_get_contents($file);
            if (preg_match('/\sstyle\s*=\s*["\x27]|<style[\s>]/i', $inhalt) === 1) {
                $treffer[] = basename($file);
            }
        }

        self::assertSame([], $treffer, 'Inline styles are blocked by style-src. Use a class in public/css/.');
    }

    /**
     * Every JS library is vendored (CLAUDE.md section 4). A <script src> or a
     * stylesheet pointing at a CDN would be blocked by the CSP - and the
     * provider would learn about every view of a page with member data.
     */
    public function testNoViewLoadsAnAssetFromAnExternalHost(): void
    {
        $treffer = [];
        foreach (self::viewFiles() as $file) {
            $inhalt = (string) file_get_contents($file);
            if (preg_match_all('#(?:src|href)\s*=\s*["\x27](?:https?:)?//#i', $inhalt, $m) > 0) {
                $treffer[] = basename($file) . ': ' . implode(', ', $m[0]);
            }
        }

        self::assertSame([], $treffer);
    }

    /** 'unsafe-eval' stays out - so our own scripts must not need it. */
    public function testOwnScriptsNeedNoEval(): void
    {
        foreach (self::ownScripts() as $file) {
            $inhalt = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression('/\beval\s*\(|new\s+Function\s*\(/', $inhalt, basename($file));
        }
    }

    public function testPolicyRestrictsScriptsToSameOrigin(): void
    {
        self::assertSame("'self'", trim(self::direktive('script-src')));
        self::assertStringNotContainsString('unsafe-eval', self::csp());
        self::assertStringNotContainsString('unsafe-inline', self::csp());
    }

    public function testPolicyAllowsNoInlineStylesEither(): void
    {
        self::assertSame("'self'", trim(self::direktive('style-src')));
    }

    public function testPolicyKeepsTheDirectivesOfTheSpec(): void
    {
        $csp = self::csp();

        foreach ([
            "default-src 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ] as $direktive) {
            self::assertStringContainsString($direktive, $csp);
        }
    }

    public function testPolicyNamesNoExternalHost(): void
    {
        self::assertDoesNotMatchRegularExpression('#https?://#i', self::csp(), 'the CSP must not list an external origin');
    }

    public function testTheSecurityHeadersAreAllPresent(): void
    {
        $htaccess = self::htaccess();

        foreach ([
            'X-Content-Type-Options',
            'Referrer-Policy',
            'Content-Security-Policy',
            'Strict-Transport-Security',
            'X-Frame-Options',
            'Permissions-Policy',
            'Cross-Origin-Opener-Policy',
        ] as $header) {
            self::assertStringContainsString($header, $htaccess);
        }
        self::assertMatchesRegularExpression('/Header always set X-Frame-Options "DENY"/', $htaccess);
        self::assertMatchesRegularExpression('/Header always set Cross-Origin-Opener-Policy "same-origin"/', $htaccess);
    }

    /** The application uses no device feature: every listed one is off. */
    public function testPermissionsPolicyAllowsNothing(): void
    {
        preg_match('/Permissions-Policy "([^"]+)"/', self::htaccess(), $m);
        self::assertNotEmpty($m, 'no Permissions-Policy header');

        foreach (array_map(trim(...), explode(',', $m[1])) as $eintrag) {
            self::assertMatchesRegularExpression('/^[a-z-]+=\(\)$/', $eintrag);
        }
        foreach (['camera', 'microphone', 'geolocation', 'payment'] as $feature) {
            self::assertStringContainsString($feature . '=()', $m[1]);
        }
    }

    /**
     * htmx is configured through <meta name="htmx-config">, never through a
     * script: script-src 'self' would drop an inline configuration block.
     * includeIndicatorStyles off keeps htmx from injecting a <style> element
     * that style-src drops anyway, selfRequestsOnly keeps an hx- attribute
     * from ever sending member data to a foreign host, and historyCacheSize 0
     * keeps htmx from storing whole pages in sessionStorage.
     */
    public function testHtmxIsConfiguredWithoutAScript(): void
    {
        $layout = (string) file_get_contents(self::repoRoot() . '/app/views/layout.php');

        self::assertMatchesRegularExpression('/<meta name="htmx-config" content=\x27[^\x27]+\x27>/', $layout);
        self::assertStringContainsString('"includeIndicatorStyles":false', $layout);
        self::assertStringContainsString('"selfRequestsOnly":true', $layout);
        // No page snapshots - member data - in sessionStorage (02 section 7).
        self::assertStringContainsString('"historyCacheSize":0', $layout);
        self::assertStringContainsString('"allowEval":false', $layout);
        // Settling "style" sets style attributes, which style-src 'self' blocks.
        self::assertStringContainsString('"attributesToSettle":["class","width","height"]', $layout);
        self::assertStringContainsString('src="/js/vendor/htmx.min.js', $layout);
    }
}
