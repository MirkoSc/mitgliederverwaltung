<?php

declare(strict_types=1);

namespace App\App;

use App\App\Mitglied\Einbettung;
use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Mitglied;
use App\Http\Request;
use App\Http\Response;
use App\Repository\MitgliedRepository;
use App\Service\Karte\Anschrift;
use App\Service\Karte\HandSuche;
use App\Service\Karte\Koordinatenermittlung;
use App\Service\Karte\Wohnort;
use App\Support\Datum;
use App\View\FlashArt;

/**
 * The block "Wohnort auf der Karte" in the tab "Daten" (issue #106): asks
 * the geocoder for the member's address now ("Jetzt suchen"), saves a
 * search address for the map only and asks with it, or removes it again.
 * The member's own address is never touched.
 *
 * One route, the button says what to do (field `aktion`); every answer is a
 * redirect back to the tab "Daten" on the page the member is shown on
 * (Einbettung, docs/spec/06-oberflaeche.md section 5) with the outcome as
 * flash message - never with an address in it.
 */
final readonly class WohnortController
{
    public const string AKTION_SUCHEN = 'suchen';
    public const string AKTION_KORRIGIEREN = 'korrigieren';
    public const string AKTION_ENTFERNEN = 'entfernen';

    /** Anchor of the block, where the redirect lands. */
    public const string ANKER = 'wohnort';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d));
    }

    public static function adresse(int $mitgliedId): string
    {
        return MitgliedController::PFAD_LISTE . '/' . $mitgliedId . '/wohnort';
    }

    /** @param array<string, string> $params */
    public function aendern(Request $r, array $params): Response
    {
        $mitglied = new MitgliedRepository($this->dienste->pdo())->finde((int) $params['id']);
        $einbettung = Einbettung::ausAnfrage($r);
        if ($mitglied === null) {
            $this->dienste->session->flash('Dieses Mitglied gibt es nicht mehr. Möglicherweise wurde es inzwischen gelöscht.', FlashArt::Warnung);

            return Response::redirect($einbettung->rueckweg());
        }
        $id = (int) $mitglied->id;
        $zurueck = Response::redirect($einbettung->adresse($id, 'daten') . '#' . self::ANKER);

        $jetzt = new \DateTimeImmutable('now', new \DateTimeZone(Datum::ZEITZONE));
        $anschrift = Wohnort::anschrift($mitglied);
        if ($mitglied->istEhemalig($jetzt) || $anschrift->istLeer()) {
            $this->dienste->session->flash('Für dieses Mitglied wird auf der Karte nicht gesucht: ehemalige Mitglieder und Anschriften ohne PLZ und Ort stehen nicht auf der Karte.', FlashArt::Warnung);

            return $zurueck;
        }

        $ermittlung = Koordinatenermittlung::aus($this->dienste);
        $aktion = $r->feld('aktion');
        switch ($aktion) {
            case self::AKTION_SUCHEN:
                $ergebnis = $ermittlung->jetztSuchen($anschrift, $jetzt);
                break;
            case self::AKTION_KORRIGIEREN:
                $suche = self::suchAnschrift($r, $anschrift);
                if ($suche->istLeer()) {
                    $this->dienste->session->flash('Bitte geben Sie für die Suche mindestens PLZ oder Ort an.', FlashArt::Fehler);

                    return $zurueck;
                }
                $ergebnis = $ermittlung->korrigieren($anschrift, $suche, $jetzt);
                break;
            case self::AKTION_ENTFERNEN:
                $ergebnis = $ermittlung->korrekturEntfernen($anschrift, $jetzt);
                break;
            default:
                $this->dienste->session->flash('Diese Aktion gibt es nicht.', FlashArt::Fehler);

                return $zurueck;
        }

        if ($aktion !== self::AKTION_SUCHEN && $ergebnis !== HandSuche::Belegt) {
            $this->protokollieren($r, $mitglied, $aktion, $ergebnis);
        }
        [$text, $art] = self::meldung($aktion, $ergebnis);
        $this->dienste->session->flash($text, $art);

        return $zurueck;
    }

    /** The search address from the form; the country is the member's. */
    private static function suchAnschrift(Request $r, Anschrift $anschrift): Anschrift
    {
        return new Anschrift(
            mb_substr($r->feld('such_strasse'), 0, 150),
            mb_substr($r->feld('such_plz'), 0, 10),
            mb_substr($r->feld('such_ort'), 0, 100),
            $anschrift->land,
        );
    }

    /** By member id and the outcome only - never the address (CLAUDE.md section 4). */
    private function protokollieren(Request $r, Mitglied $mitglied, string $aktion, HandSuche $ergebnis): void
    {
        $this->dienste->audit()->protokollieren(
            AuditAktion::KartenSucheGeaendert,
            $this->dienste->session->userId(),
            'mitglied',
            (int) $mitglied->id,
            [
                'korrektur' => $aktion === self::AKTION_KORRIGIEREN ? 'gesetzt' : 'entfernt',
                'ergebnis' => match ($ergebnis) {
                    HandSuche::Gefunden => 'gefunden',
                    HandSuche::NichtGefunden => 'nicht_gefunden',
                    default => 'offen',
                },
            ],
            $r->ip,
        );
    }

    /**
     * The flash message for an outcome.
     *
     * @return array{string, FlashArt}
     */
    private static function meldung(string $aktion, HandSuche $ergebnis): array
    {
        $gemerkt = match ($aktion) {
            self::AKTION_KORRIGIEREN => 'Die Such-Anschrift ist gespeichert. ',
            self::AKTION_ENTFERNEN => 'Die Such-Anschrift ist entfernt. ',
            default => '',
        };

        return match ($ergebnis) {
            HandSuche::Gefunden => [$gemerkt . 'Die Anschrift wurde auf der Karte gefunden.', FlashArt::Ok],
            HandSuche::NichtGefunden => [
                $aktion === self::AKTION_KORRIGIEREN
                    ? $gemerkt . 'Auch mit ihr wurde die Anschrift auf der Karte nicht gefunden. Prüfen Sie Straße, PLZ und Ort.'
                    : $gemerkt . 'Die Anschrift wurde auf der Karte nicht gefunden.',
                FlashArt::Warnung,
            ],
            HandSuche::Belegt => ['Der Kartendienst ist gerade mit einer anderen Suche beschäftigt. Es wurde nichts geändert – bitte versuchen Sie es in einer Minute noch einmal.', FlashArt::Info],
            HandSuche::Ruht => [$gemerkt . 'Der Kartendienst hat zuletzt nicht geantwortet und ruht ein paar Minuten; danach wird automatisch gesucht.', FlashArt::Info],
            HandSuche::Fehler => [$gemerkt . 'Der Kartendienst antwortet gerade nicht; in ein paar Minuten wird automatisch gesucht.', FlashArt::Warnung],
        };
    }
}
