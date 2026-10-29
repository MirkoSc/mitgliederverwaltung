<?php

declare(strict_types=1);

use App\App\ZweiterFaktorController as Konto;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\PublicPages\ZweiterFaktorAnmeldung as Anmeldung;
use App\View\Navigation;

/**
 * The second factor (docs/spec/02-sicherheit.md section 3): the second step
 * of the login below /anmelden (public, E-F3 - nobody is logged in yet), and
 * the own setup in the user menu (every logged-in account, no right needed;
 * the only pages an account without a second factor reaches).
 */
return static function (Routen $routen): void {
    $oeffentlich = Zugriff::oeffentlich();
    $angemeldet = Zugriff::angemeldet();
    $a = static fn(): Anmeldung => Anmeldung::aus($routen->dienste());
    $k = static fn(): Konto => Konto::aus($routen->dienste());

    $routen->get(Anmeldung::BESTAETIGEN, $oeffentlich, static fn(Request $r): Response => $a()->form($r));
    $routen->post(Anmeldung::BESTAETIGEN, $oeffentlich, static fn(Request $r): Response => $a()->pruefen($r));
    $routen->post(Anmeldung::CODE_SENDEN, $oeffentlich, static fn(Request $r): Response => $a()->codeSenden($r));

    $routen->get(Navigation::ZWEITER_FAKTOR, $angemeldet, static fn(Request $r): Response => $k()->seite($r));
    $routen->post(Konto::EINRICHTEN, $angemeldet, static fn(Request $r): Response => $k()->einrichten($r));
    $routen->post(Konto::ABSCHLIESSEN, $angemeldet, static fn(Request $r): Response => $k()->abschliessen($r));
    $routen->post(Konto::ABBRECHEN, $angemeldet, static fn(Request $r): Response => $k()->abbrechen($r));
    $routen->post(Konto::BESTAETIGEN, $angemeldet, static fn(Request $r): Response => $k()->bestaetigen($r));
    $routen->post(Konto::CODE_SENDEN, $angemeldet, static fn(Request $r): Response => $k()->codeSenden($r));
    $routen->post(Konto::BACKUP_CODES, $angemeldet, static fn(Request $r): Response => $k()->backupCodes($r));
    $routen->post(Konto::GERAETE_VERGESSEN, $angemeldet, static fn(Request $r): Response => $k()->geraeteVergessen($r));
};
