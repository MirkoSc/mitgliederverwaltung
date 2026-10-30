<?php

declare(strict_types=1);

namespace App\Tests\Service\Karte;

use App\Service\Karte\HttpAntwort;
use App\Service\Karte\KachelProxy;
use App\Tests\Support\FakeHttpAbruf;
use PHPUnit\Framework\TestCase;

final class KachelProxyTest extends TestCase
{
    private string $verzeichnis;

    private \DateTimeImmutable $jetzt;

    protected function setUp(): void
    {
        $this->verzeichnis = sys_get_temp_dir() . '/mv-kacheln-' . bin2hex(random_bytes(4));
        $this->jetzt = new \DateTimeImmutable('2026-10-30 12:00:00');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->verzeichnis)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->verzeichnis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $eintrag) {
            $eintrag->isDir() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
        }
        rmdir($this->verzeichnis);
    }

    private function proxy(FakeHttpAbruf $http, int $maxBytes = KachelProxy::MAX_BYTES): KachelProxy
    {
        return new KachelProxy($this->verzeichnis, $http, 'Mitgliederverwaltung/test (+https://verein.example.org)', $maxBytes);
    }

    public function testTheFirstRequestFetchesAndTheSecondComesFromTheCache(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => new HttpAntwort(200, FakeHttpAbruf::png(), 'image/png'));
        $proxy = $this->proxy($http);

        self::assertSame(FakeHttpAbruf::png(), $proxy->kachel(13, 4400, 2850, $this->jetzt));
        self::assertSame(['https://tile.openstreetmap.org/13/4400/2850.png'], $http->urls());
        self::assertSame('Mitgliederverwaltung/test (+https://verein.example.org)', $http->anfragen[0]['userAgent']);
        self::assertFileExists($this->verzeichnis . '/13/4400/2850.png');

        self::assertSame(FakeHttpAbruf::png(), $proxy->kachel(13, 4400, 2850, $this->jetzt->modify('+6 days')));
        self::assertCount(1, $http->anfragen, 'a fresh tile is not fetched again');
    }

    public function testAnExpiredTileIsFetchedAgain(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => new HttpAntwort(200, FakeHttpAbruf::png(), 'image/png'));
        $proxy = $this->proxy($http);

        $proxy->kachel(10, 500, 300, $this->jetzt);
        $proxy->kachel(10, 500, 300, $this->jetzt->modify('+8 days'));

        self::assertCount(2, $http->anfragen);
    }

    public function testWhenTheServerFailsAnExpiredCopyStillServes(): void
    {
        $antworten = [new HttpAntwort(200, FakeHttpAbruf::png()), new HttpAntwort(503)];
        $proxy = $this->proxy(new FakeHttpAbruf(static function () use (&$antworten): HttpAntwort {
            return array_shift($antworten);
        }));

        $proxy->kachel(10, 500, 300, $this->jetzt);

        self::assertSame(FakeHttpAbruf::png(), $proxy->kachel(10, 500, 300, $this->jetzt->modify('+30 days')));
    }

    public function testNothingButAPngIsCachedOrServed(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => new HttpAntwort(200, '<html>blocked</html>', 'text/html'));

        self::assertNull($this->proxy($http)->kachel(10, 500, 300, $this->jetzt));
        self::assertFileDoesNotExist($this->verzeichnis . '/10/500/300.png');
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function ungueltigeKacheln(): iterable
    {
        yield 'zoom too far out' => [4, 0, 0];
        yield 'zoom too far in' => [19, 0, 0];
        yield 'x beyond the edge' => [5, 32, 0];
        yield 'y beyond the edge' => [5, 0, 32];
        yield 'negative' => [5, -1, 0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeKacheln')]
    public function testAnInvalidTileIsNeverRequested(int $z, int $x, int $y): void
    {
        $http = new FakeHttpAbruf();

        self::assertFalse(KachelProxy::gueltig($z, $x, $y));
        self::assertNull($this->proxy($http)->kachel($z, $x, $y, $this->jetzt));
        self::assertSame([], $http->anfragen);
    }

    public function testTheEdgesOfTheZoomRangeAreValid(): void
    {
        self::assertTrue(KachelProxy::gueltig(KachelProxy::ZOOM_MIN, 31, 31));
        self::assertTrue(KachelProxy::gueltig(KachelProxy::ZOOM_MAX, (1 << KachelProxy::ZOOM_MAX) - 1, 0));
    }

    public function testTheCleanupRemovesExpiredTilesAndKeepsTheCacheBelowItsCap(): void
    {
        $http = new FakeHttpAbruf(static fn(): HttpAntwort => new HttpAntwort(200, FakeHttpAbruf::png() . str_repeat("\0", 1000)));
        $groesse = strlen(FakeHttpAbruf::png()) + 1000;
        $proxy = $this->proxy($http, 5 * $groesse);

        $proxy->kachel(10, 1, 1, $this->jetzt->modify('-10 days'));   // expired
        for ($i = 0; $i < 6; $i++) {                                   // over the cap
            $proxy->kachel(11, $i, 1, $this->jetzt->modify(sprintf('-%d hours', 6 - $i)));
        }

        $entfernt = $proxy->aufraeumen($this->jetzt);

        self::assertFileDoesNotExist($this->verzeichnis . '/10/1/1.png');
        self::assertDirectoryDoesNotExist($this->verzeichnis . '/10', 'empty folders go too');
        // 6 left, cap 5 -> trimmed to 80 % = 4, the oldest first.
        self::assertSame(3, $entfernt);
        self::assertFileDoesNotExist($this->verzeichnis . '/11/0/1.png');
        self::assertFileDoesNotExist($this->verzeichnis . '/11/1/1.png');
        self::assertFileExists($this->verzeichnis . '/11/5/1.png');
        self::assertCount(4, glob($this->verzeichnis . '/11/*/1.png') ?: []);
    }

    public function testTheCleanupOfAMissingCacheIsANoOp(): void
    {
        self::assertSame(0, $this->proxy(new FakeHttpAbruf())->aufraeumen($this->jetzt));
    }
}
