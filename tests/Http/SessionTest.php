<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Session;
use App\View\FlashArt;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        new Session()->start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheCsrfTokenIsAcceptedAsFieldAndAsHeader(): void
    {
        $session = new Session();
        $token = $session->csrfToken();

        self::assertSame($token, $session->csrfToken(), 'stable within the session');
        self::assertTrue($session->checkCsrf(new Request(HttpMethod::Post, '/x', post: ['_csrf' => $token])));
        self::assertTrue($session->checkCsrf(new Request(HttpMethod::Post, '/x', headers: ['x-csrf-token' => $token])));
        self::assertFalse($session->checkCsrf(new Request(HttpMethod::Post, '/x', post: ['_csrf' => 'falsch'])));
        self::assertFalse($session->checkCsrf(new Request(HttpMethod::Post, '/x')));
        self::assertFalse($session->checkCsrf(new Request(HttpMethod::Post, '/x', post: ['_csrf' => [$token]])), 'an array is no token');
    }

    public function testWithoutATokenInTheSessionNothingIsAccepted(): void
    {
        self::assertFalse(new Session()->checkCsrf(new Request(HttpMethod::Post, '/x', post: ['_csrf' => ''])));
    }

    /** The token of the anonymous form must not stay valid after login. */
    public function testLoginReplacesTheToken(): void
    {
        $session = new Session();
        $vorher = $session->csrfToken();

        $session->login(3);

        self::assertNotSame($vorher, $session->csrfToken());
        self::assertSame(3, $session->userId());
    }

    public function testASessionWithoutTimestampsCountsAsExpired(): void
    {
        $_SESSION['user_id'] = 3;

        self::assertTrue(new Session()->isExpired(1800, 43200));
    }

    public function testAFlashIsShownOnce(): void
    {
        $session = new Session();
        $session->flash('Gespeichert.', FlashArt::Ok);

        $flash = $session->pullFlash();
        self::assertSame('Gespeichert.', $flash?->text);
        self::assertSame(FlashArt::Ok, $flash->art);
        self::assertNull($session->pullFlash());
    }

    /**
     * Logout, password reset and invitation destroy the session and start a
     * fresh one for the flash on the login page. That fresh one must not be
     * the id the request still carries in its cookie: the browser already
     * got the regenerated id, so a flash stored under the old one was lost
     * (issue #36: "Ihr Passwort ist gespeichert" never appeared).
     */
    public function testAFlashAfterDestroyDoesNotGoBackToTheOldId(): void
    {
        $session = new Session();
        $alt = (string) session_id();
        $_COOKIE[session_name()] = $alt;

        try {
            $session->destroy();
            $session->start();
            $session->flash('Ihr Passwort ist gespeichert.');

            self::assertNotSame($alt, session_id());
            self::assertNotSame('', session_id());
            self::assertSame('Ihr Passwort ist gespeichert.', $session->pullFlash()?->text);
        } finally {
            unset($_COOKIE[session_name()]);
        }
    }

    public function testDestroyLogsOut(): void
    {
        $session = new Session();
        $session->login(3);

        $session->destroy();
        $session->start();

        self::assertNull($session->userId());
    }

    /** cache_limiter nocache: no member data in a browser cache (02 section 7). */
    public function testTheSessionPinsNoCacheHeaders(): void
    {
        self::assertSame('nocache', Session::OPTIONEN['cache_limiter']);
        self::assertSame('__Host-sid', Session::NAME_HTTPS);
    }

    /** The garbage collector must not end a session the guard still allows. */
    public function testSessionFilesOutliveTheLongestIdleLimit(): void
    {
        self::assertGreaterThan(LoginGuard::LEERLAUF_BARRIEREFREI_SEKUNDEN, Session::OPTIONEN['gc_maxlifetime']);
    }
}
