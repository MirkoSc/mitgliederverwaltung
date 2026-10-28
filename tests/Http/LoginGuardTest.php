<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Konto;
use App\Domain\Permission;
use App\Domain\Rolle;
use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Zugriff;
use App\Tests\Support\TestKonten;
use App\View\View;
use PHPUnit\Framework\TestCase;

/**
 * The gate in front of /app and /admin (docs/spec/02-sicherheit.md
 * section 4): timeouts including the Barrierefrei-Modus, `session_epoch`,
 * background requests, rights.
 */
final class LoginGuardTest extends TestCase
{
    private TestKonten $konten;

    private View $view;

    protected function setUp(): void
    {
        $this->konten = new TestKonten();
        $this->view = new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test');
        new Session()->start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function anmelden(Konto $konto, ?\DateTimeImmutable $am = null, int $epoch = 0): void
    {
        $this->konten->hinzufuegen($konto);
        new Session()->login($konto->id, $am, $epoch);
    }

    private function aufrufen(Zugriff $zugriff, string $pfad = '/app/mitglieder', array $headers = []): Response
    {
        $guard = new LoginGuard(new Session(), $this->view, $this->konten);
        $antwort = $guard->pruefe($zugriff, static fn(): Response => Response::html('ERREICHT'))(
            new Request(HttpMethod::Get, $pfad, headers: $headers),
            [],
        );
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private static function vor(int $sekunden): \DateTimeImmutable
    {
        return new \DateTimeImmutable('-' . $sekunden . ' seconds');
    }

    public function testAnonymousVisitorsGoToTheLoginAndComeBackToWhereTheyWanted(): void
    {
        $antwort = $this->aufrufen(Zugriff::angemeldet());

        self::assertSame(302, $antwort->status);
        self::assertSame('/anmelden?weiter=%2Fapp%2Fmitglieder', $antwort->headers['Location']);
    }

    public function testJsonRoutesAnswer401Instead(): void
    {
        $antwort = $this->aufrufen(Zugriff::angemeldet()->alsApi());

        self::assertSame(401, $antwort->status);
        self::assertStringContainsString('application/json', $antwort->headers['Content-Type']);
    }

    public function testALoggedInAccountWithTheRightGetsThrough(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Vorstand));

        self::assertSame('ERREICHT', $this->aufrufen(Zugriff::recht(Permission::MitgliederSehen))->body);
        self::assertSame(5, $this->view->konto()?->id, 'the layout learns who is logged in');
    }

    public function testWithoutTheRightTheAnswerIs403NotTheLogin(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Vorstand));

        $antwort = $this->aufrufen(Zugriff::recht(Permission::BeitraegeVerwalten), '/app/beitraege');

        self::assertSame(403, $antwort->status);
        self::assertStringContainsString('Keine Berechtigung', $antwort->body);
    }

    public function testIdleForThirtyMinutesEndsTheSession(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Admin), self::vor(LoginGuard::LEERLAUF_SEKUNDEN + 1));

        $antwort = $this->aufrufen(Zugriff::angemeldet());

        self::assertSame(302, $antwort->status);
        self::assertStringContainsString('abgelaufen=1', $antwort->headers['Location']);
        self::assertNull(new Session()->userId(), 'the session is gone, not only refused');
    }

    public function testJustBelowTheIdleLimitTheSessionLives(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Admin), self::vor(LoginGuard::LEERLAUF_SEKUNDEN - 60));

        self::assertSame('ERREICHT', $this->aufrufen(Zugriff::angemeldet())->body);
    }

    /** 06 section 4: the Barrierefrei-Modus doubles the idle time to 60 minutes. */
    public function testTheBarrierefreiModusAllowsSixtyMinutes(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Admin, barrierefrei: true), self::vor(45 * 60));
        self::assertSame('ERREICHT', $this->aufrufen(Zugriff::angemeldet())->body);

        $this->anmelden(new Konto(6, 'Test', Rolle::Admin, barrierefrei: true), self::vor(61 * 60));
        self::assertSame(302, $this->aufrufen(Zugriff::angemeldet())->status);
    }

    public function testTwelveHoursEndTheSessionEvenWhenActive(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Admin), self::vor(LoginGuard::HOECHSTDAUER_SEKUNDEN + 1));
        $_SESSION['last_seen_at'] = time();

        self::assertSame(302, $this->aufrufen(Zugriff::angemeldet())->status);
    }

    /** A password reset elsewhere moves the account's epoch on (02 section 4). */
    public function testAChangedSessionEpochEndsTheSession(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Admin, sessionEpoch: 2), epoch: 1);

        self::assertSame(302, $this->aufrufen(Zugriff::angemeldet())->status);
    }

    /** Locking or deleting the account takes effect at once. */
    public function testAnAccountThatIsGoneEndsTheSession(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Admin));
        $this->konten->entfernen(5);

        self::assertSame(302, $this->aufrufen(Zugriff::angemeldet())->status);
        self::assertNull(new Session()->userId());
    }

    /** A background poll is no sign of a person at the screen (02 section 4). */
    public function testABackgroundRequestDoesNotExtendTheSession(): void
    {
        $this->anmelden(new Konto(5, 'Test', Rolle::Admin), self::vor(600));
        $vorher = $_SESSION['last_seen_at'];

        $this->aufrufen(Zugriff::angemeldet(), headers: [LoginGuard::HINTERGRUND_HEADER => '1']);
        self::assertSame($vorher, $_SESSION['last_seen_at'], 'a background request is no activity');

        $this->aufrufen(Zugriff::angemeldet());
        self::assertGreaterThan($vorher, $_SESSION['last_seen_at'], 'a request of the user is');
    }
}
