<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Http\Request;
use App\Http\Response;
use App\Repository\MitgliedRepository;
use App\View\Area;
use App\View\FlashArt;

/**
 * "Zurückholen" in the tab Verlauf (docs/spec/07-mitglieder.md section 7):
 * the values of an old version become a NEW version at the end.
 */
final readonly class VerlaufController
{
    public function __construct(private Dienste $dienste, private MitgliedRepository $mitglieder)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d, new MitgliedRepository($d->pdo())));
    }

    public static function adresse(int $mitgliedId, int $nr): string
    {
        return '/app/mitglieder/' . $mitgliedId . '/verlauf/' . $nr . '/zurueckholen';
    }

    /** @param array<string, string> $params */
    public function zurueckholen(Request $r, array $params): Response
    {
        $id = (int) $params['id'];
        $nr = (int) $params['nr'];

        try {
            $ergebnis = $this->mitglieder->wiederherstellen($id, $nr, $this->dienste->session->userId());
        } catch (\OutOfBoundsException) {
            return Response::html($this->dienste->view->render('fehler', [
                'title' => 'Version nicht gefunden',
                'meldung' => 'Dieses Mitglied oder diese Version gibt es nicht (mehr).',
                'startseite' => MitgliedController::PFAD_LISTE,
            ], Area::App), 404);
        }

        if ($ergebnis->neueVersion) {
            $this->dienste->audit()->protokollieren(
                AuditAktion::VersionZurueckgeholt,
                $this->dienste->session->userId(),
                'mitglied',
                $id,
                ['mitgliedsnummer' => $ergebnis->mitglied->mitgliedsnummer, 'aus_version' => $nr, 'neue_version' => $ergebnis->mitglied->version],
                $r->ip,
            );
        }

        $text = $ergebnis->neueVersion
            ? sprintf('Version %d ist zurückgeholt – als neue Version %d.', $nr, $ergebnis->mitglied->version)
            : sprintf('Nichts geändert – das Mitglied hat schon die Werte von Version %d.', $nr);
        $this->dienste->session->flash(
            implode(' ', [$text, ...$ergebnis->hinweise]),
            $ergebnis->hinweise === [] ? FlashArt::Ok : FlashArt::Warnung,
        );

        return Response::redirect(MitgliedController::adresse($id) . '&reiter=verlauf');
    }
}
