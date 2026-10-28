<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Service\Update\ReleaseDownloader;
use PHPUnit\Framework\TestCase;

/**
 * The release workflow (docs/spec/04-installation-und-update.md section 1)
 * and the downloader have to agree on names: the ZIP the workflow builds is
 * the one ReleaseDownloader::ZIP_PATTERN looks for, and checksums.txt names
 * it and every file inside it. The workflow itself only runs on a tag, so
 * its shape is pinned here.
 */
final class ReleaseWorkflowTest extends TestCase
{
    private static function workflow(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/release.yml');
    }

    public function testTheZipNameMatchesWhatTheDownloaderLooksFor(): void
    {
        self::assertStringContainsString('"dist/mitgliederverwaltung-${GITHUB_REF_NAME}.zip"', self::workflow());
        self::assertSame(1, preg_match(ReleaseDownloader::ZIP_PATTERN, 'mitgliederverwaltung-v1.2.3.zip'));
        self::assertSame(0, preg_match(ReleaseDownloader::ZIP_PATTERN, 'vereinsbelege-v1.2.3.zip'));
    }

    /**
     * Exactly what 04 section 1 lists - and nothing of tests/, tools/,
     * docs/ or the rest of docker/ (the dev database password lives there).
     */
    public function testTheZipCarriesTheReleaseAndNothingElse(): void
    {
        self::assertSame(1, preg_match('#zip -r "[^"]+" \\\\\n\s+([^\n]+)\n#', self::workflow(), $treffer));
        $inhalt = preg_split('/\s+/', trim($treffer[1])) ?: [];
        sort($inhalt);

        self::assertSame(
            ['.htaccess', 'VERSION', 'app', 'bin', 'docker/web', 'migrations', 'public', 'vendor'],
            $inhalt,
        );
    }

    public function testDependenciesAreInstalledWithoutDevPackages(): void
    {
        self::assertStringContainsString('composer install --no-dev --optimize-autoloader', self::workflow());
        self::assertStringContainsString('echo "${GITHUB_REF_NAME#v}" > VERSION', self::workflow());
    }

    /**
     * checksums.txt: one sha256sum line per release asset, then one per
     * file inside the ZIP prefixed with "release/" - for the integrity check
     * (04 section 5). The asset lines are what verifyChecksum() reads.
     */
    public function testChecksumsCoverTheAssetsAndEveryFileInTheZip(): void
    {
        $workflow = self::workflow();

        self::assertStringContainsString('cd dist && sha256sum * > checksums.txt', $workflow);
        self::assertStringContainsString("sed 's#^\\([0-9a-f]\\{64\\}\\)  \\./#\\1  release/#' >> ../dist/checksums.txt", $workflow);
        self::assertStringContainsString("grep -q '^[0-9a-f]\\{64\\}  release/VERSION$' ../dist/checksums.txt", $workflow);
    }

    /**
     * Every tag starts as a pre-release (beta channel); "latest" (stable)
     * is set by hand after the test instance checked it.
     */
    public function testEveryReleaseStartsAsAPreRelease(): void
    {
        self::assertMatchesRegularExpression("#tags: \\['v\\*\\.\\*\\.\\*'\\]#", self::workflow());
        self::assertStringContainsString('prerelease: true', self::workflow());
        self::assertStringNotContainsString('make_latest', self::workflow());
    }
}
