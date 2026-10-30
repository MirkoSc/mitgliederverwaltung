<?php

declare(strict_types=1);

namespace App\Tests\Service\SystemCheck;

use App\Service\SystemCheck\CurlHeaderProbe;
use PHPUnit\Framework\TestCase;

/** The parsing of raw header lines; the request itself is not made in tests. */
final class CurlHeaderProbeTest extends TestCase
{
    public function testTheLastHopWins(): void
    {
        $result = CurlHeaderProbe::auswerten([
            "HTTP/1.1 301 Moved Permanently\r\n",
            "Location: https://verein.example/anmelden\r\n",
            "X-Only-First: yes\r\n",
            "\r\n",
            "HTTP/2 200\r\n",
            "Content-Security-Policy: default-src 'self'\r\n",
            "Set-Cookie: a=1\r\n",
            "Set-Cookie: b=2\r\n",
            "\r\n",
        ]);

        self::assertSame(200, $result['status'] ?? null);
        self::assertSame("default-src 'self'", $result['headers']['content-security-policy']);
        self::assertSame('a=1, b=2', $result['headers']['set-cookie']);
        self::assertArrayNotHasKey('x-only-first', $result['headers']);
        self::assertArrayNotHasKey('location', $result['headers']);
    }

    public function testNoStatusLineMeansNoAnswer(): void
    {
        self::assertNull(CurlHeaderProbe::auswerten([]));
        self::assertNull(CurlHeaderProbe::auswerten(["X-Foo: bar\r\n"]));
    }

    public function testANonHttpUrlIsNotRequested(): void
    {
        self::assertNull(new CurlHeaderProbe()('file:///etc/passwd'));
    }
}
