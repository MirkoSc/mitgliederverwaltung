<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Cookie;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class CookieTest extends TestCase
{
    public function testOverHttpsTheHostPrefixAndItsRulesApply(): void
    {
        $kopf = Cookie::hostPrefixed('td', 'wert', secure: true, maxAge: 60)->header();

        self::assertStringStartsWith('__Host-td=wert; Path=/; Max-Age=60; Expires=', $kopf);
        self::assertStringEndsWith('; HttpOnly; Secure; SameSite=Lax', $kopf);
        self::assertStringNotContainsString('Domain', $kopf);
    }

    public function testOverPlainHttpThePrefixIsLeftOut(): void
    {
        self::assertSame('td=wert; Path=/; HttpOnly; SameSite=Lax', Cookie::hostPrefixed('td', 'wert', secure: false)->header());
    }

    public function testBothNamesAreRead(): void
    {
        self::assertSame('a', Cookie::hostPrefixedFrom(new Request(HttpMethod::Get, '/', cookies: ['__Host-td' => 'a']), 'td'));
        self::assertSame('b', Cookie::hostPrefixedFrom(new Request(HttpMethod::Get, '/', cookies: ['td' => 'b']), 'td'));
    }

    public function testExpiredKeepsNameAndAttributes(): void
    {
        $kopf = Cookie::hostPrefixed('td', 'wert', secure: true)->expired()->header();

        self::assertStringStartsWith('__Host-td=; Path=/; Max-Age=0;', $kopf);
        self::assertStringContainsString('Secure', $kopf);
    }

    public function testInvalidValuesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cookie('td', "a;b", true);
    }

    public function testASecondCookieDoesNotReplaceTheFirst(): void
    {
        $antwort = Response::html('x')
            ->withCookie(new Cookie('a', '1', false))
            ->withCookie(new Cookie('b', '2', false));

        self::assertStringStartsWith('a=1', $antwort->headers['Set-Cookie']);
        self::assertCount(1, $antwort->additionalCookies);
        self::assertSame('b', $antwort->additionalCookies[0]->name);
    }
}
