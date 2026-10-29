<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Http\Request;
use App\Http\Response;
use App\Repository\BenutzerRepository;
use App\Service\Account\Kontomails;
use App\Service\Account\PasswordPolicy;
use App\Service\Account\PasswortAenderung;
use App\View\Area;
use App\View\Navigation;

/**
 * The own account, from the user menu (docs/spec/06-oberflaeche.md
 * section 2): change the own password. The second factor (issue #8) and the
 * Barrierefrei-Modus (issue #10) add their own routes next to it.
 */
final readonly class KontoController
{
    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function passwortForm(Request $request): Response
    {
        return $this->seite();
    }

    public function passwort(Request $request): Response
    {
        $session = $this->dienste->session;
        $userId = $session->userId();
        if ($userId === null) {
            // The guard let the request through, so this cannot happen.
            return Response::redirect('/anmelden');
        }

        $ergebnis = PasswortAenderung::aus($this->dienste)->aendern(
            $userId,
            self::roh($request, 'aktuell'),
            self::roh($request, 'passwort'),
            self::roh($request, 'passwort_wiederholung'),
        );
        if (!$ergebnis->istErfolg()) {
            return $this->seite($ergebnis->fehler, 422);
        }

        // Every other session of the account just ended; this one lives on
        // with the new epoch and a fresh id.
        $session->adoptEpoch($ergebnis->sessionEpoch);
        $this->dienste->audit()->protokollieren(AuditAktion::PasswortGeaendert, $userId, 'user', $userId, ip: $request->ip);

        $benutzer = new BenutzerRepository($this->dienste->pdo())->findById($userId);
        if ($benutzer !== null) {
            Kontomails::aus($this->dienste)->hinweis($benutzer->email, Kontomails::PASSWORT_GEAENDERT);
        }
        $session->flash('Ihr Passwort ist geändert. Auf anderen Geräten sind Sie jetzt abgemeldet.');

        return Response::redirect(Navigation::PASSWORT);
    }

    /** Switches the Barrierefrei-Modus of the own account (06 section 4). */
    public function barrierefrei(Request $request): Response
    {
        $userId = $this->dienste->session->userId();
        if ($userId === null) {
            return Response::redirect('/anmelden');
        }

        $repo = new BenutzerRepository($this->dienste->pdo());
        $benutzer = $repo->findById($userId);
        if ($benutzer === null) {
            return Response::redirect('/anmelden');
        }
        $repo->updateBarrierefrei($userId, !$benutzer->barrierefrei);

        return Response::redirect(Navigation::startseiteFuer($benutzer->konto()));
    }

    /**
     * The "Weiterarbeiten" button of the idle warning: the guard has already
     * pushed the idle timeout out by the time this runs.
     */
    public function weiterarbeiten(Request $request): Response
    {
        return Response::json(['ok' => true]);
    }

    /**
     * @param list<string> $fehler
     */
    private function seite(array $fehler = [], int $status = 200): Response
    {
        return Response::html($this->dienste->view->render('konto/passwort', [
            'title' => 'Passwort ändern',
            'fehler' => $fehler,
            'minLaenge' => PasswordPolicy::MIN_LAENGE,
        ], Area::App), $status);
    }

    private static function roh(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }
}
