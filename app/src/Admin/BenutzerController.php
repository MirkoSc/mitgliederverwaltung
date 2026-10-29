<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Rolle;
use App\Http\Request;
use App\Http\Response;
use App\Service\Account\BenutzerRegelVerstoss;
use App\Service\Account\BenutzerVerwaltung;
use App\Service\Account\Einladung;
use App\Service\Account\Kontomails;
use App\Service\Mail\MailAttemptResult;
use App\View\FlashArt;

/**
 * The forms of the settings section Benutzer (App\Admin\Einstellungen\
 * BenutzerAbschnitt, docs/spec/02-sicherheit.md section 5): invite, send the
 * invitation again, change the role, lock, unlock, delete. Admin only
 * (`admin.benutzer`, declared by app/routes/benutzer.php).
 *
 * Every form answers with a redirect back to the section and a flash
 * (post/redirect/get). A flash names the account (users are not members,
 * 02 section 5) but never its address.
 */
final readonly class BenutzerController
{
    public const string ZIEL = '/admin#benutzer';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function einladen(Request $request): Response
    {
        $rolle = Rolle::tryFrom($request->feld('rolle'));
        if ($rolle === null) {
            return $this->zurueck('Bitte eine Rolle wählen.', FlashArt::Fehler);
        }

        try {
            $einladung = Einladung::aus($this->dienste)->einladen($request->feld('email'), $request->feld('name'), $rolle);
        } catch (BenutzerRegelVerstoss $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }
        $this->protokollieren($request, AuditAktion::BenutzerAngelegt, $einladung['userId'], ['rolle' => $rolle->value, 'einladung' => 'neu']);

        return $this->versandMelden(
            Kontomails::aus($this->dienste)->einladung($request, $einladung['email'], $einladung['token']),
            'Die Einladung',
        );
    }

    public function erneutEinladen(Request $request, int $id): Response
    {
        try {
            $einladung = Einladung::aus($this->dienste)->erneutSenden($id);
        } catch (BenutzerRegelVerstoss $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }
        $this->protokollieren($request, AuditAktion::BenutzerGeaendert, $id, ['einladung' => 'erneut']);

        return $this->versandMelden(
            Kontomails::aus($this->dienste)->einladung($request, $einladung['email'], $einladung['token']),
            'Die neue Einladung',
        );
    }

    public function rolle(Request $request, int $id): Response
    {
        $rolle = Rolle::tryFrom($request->feld('rolle'));
        if ($rolle === null) {
            return $this->zurueck('Bitte eine Rolle wählen.', FlashArt::Fehler);
        }

        return $this->schritt(
            $request,
            AuditAktion::RolleGeaendert,
            ['rolle' => $rolle->value],
            fn(BenutzerVerwaltung $v, int $admin) => $v->rolleAendern($admin, $id, $rolle),
            fn(string $name): string => sprintf('%s hat jetzt die Rolle %s.', $name, $rolle->bezeichnung()),
        );
    }

    public function sperren(Request $request, int $id): Response
    {
        return $this->schritt(
            $request,
            AuditAktion::BenutzerGesperrt,
            [],
            fn(BenutzerVerwaltung $v, int $admin) => $v->sperren($admin, $id),
            fn(string $name): string => sprintf('%s ist gesperrt und auf allen Geräten abgemeldet.', $name),
        );
    }

    public function entsperren(Request $request, int $id): Response
    {
        return $this->schritt(
            $request,
            AuditAktion::BenutzerGeaendert,
            ['gesperrt' => false],
            fn(BenutzerVerwaltung $v, int $admin) => $v->entsperren($id),
            fn(string $name): string => sprintf('%s kann sich wieder anmelden.', $name),
        );
    }

    public function loeschen(Request $request, int $id): Response
    {
        // The tick box of the form; checked here too, not only by the
        // browser's `required`.
        if ($request->feld('bestaetigt') !== '1') {
            return $this->zurueck('Bitte bestätigen Sie das Löschen mit dem Haken neben dem Knopf.', FlashArt::Fehler);
        }

        return $this->schritt(
            $request,
            AuditAktion::BenutzerGeloescht,
            [],
            fn(BenutzerVerwaltung $v, int $admin) => $v->loeschen($admin, $id),
            fn(string $name): string => sprintf('%s ist gelöscht.', $name),
        );
    }

    /**
     * One change of a user, recorded in the audit log (02 section 8) once
     * it has happened.
     *
     * @param array<string, scalar|null> $details
     * @param \Closure(BenutzerVerwaltung, int): \App\Domain\Benutzer $schritt
     * @param \Closure(string): string $meldung
     */
    private function schritt(Request $request, AuditAktion $aktion, array $details, \Closure $schritt, \Closure $meldung): Response
    {
        $admin = $this->dienste->session->userId() ?? 0;
        try {
            $benutzer = $schritt(BenutzerVerwaltung::aus($this->dienste), $admin);
        } catch (BenutzerRegelVerstoss $e) {
            return $this->zurueck($e->getMessage(), FlashArt::Fehler);
        }
        $this->protokollieren($request, $aktion, $benutzer->id, $details);

        return $this->zurueck($meldung($benutzer->name));
    }

    /**
     * The user concerned only by id - names and addresses of accounts stay
     * out of the log.
     *
     * @param array<string, scalar|null> $details
     */
    private function protokollieren(Request $request, AuditAktion $aktion, ?int $betroffen, array $details): void
    {
        $this->dienste->audit()->protokollieren($aktion, $this->dienste->session->userId(), 'user', $betroffen, $details, $request->ip);
    }

    /**
     * Says honestly whether the mail went out. A failed attempt stays in
     * the queue - the cron retries it.
     */
    private function versandMelden(?MailAttemptResult $versand, string $was): Response
    {
        if ($versand === null) {
            return $this->zurueck(
                $was . ' ist angelegt, aber keine Mail versendet: In den Mail-Einstellungen fehlt die öffentliche Adresse dieser Anwendung. '
                    . 'Tragen Sie sie ein und senden Sie die Einladung erneut.',
                FlashArt::Warnung,
            );
        }
        if (!$versand->erfolg) {
            return $this->zurueck(
                $was . ' ist angelegt, die Mail ist aber noch nicht raus (' . (string) $versand->fehlermeldung . '). Sie wird automatisch erneut versucht.',
                FlashArt::Warnung,
            );
        }

        return $this->zurueck($was . ' ist versendet.');
    }

    private function zurueck(string $meldung, FlashArt $art = FlashArt::Ok): Response
    {
        $this->dienste->session->flash($meldung, $art);

        return Response::redirect(self::ZIEL);
    }
}
