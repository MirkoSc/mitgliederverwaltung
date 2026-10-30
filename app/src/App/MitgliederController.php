<?php

declare(strict_types=1);

namespace App\App;

use App\App\Mitglied\Einbettung;
use App\App\Mitglied\MitgliedAnsicht;
use App\Dienste;
use App\Domain\MitgliederFilter;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\MitgliederListeRepository;
use App\View\Area;

/**
 * The page "Mitglieder" (docs/spec/06-oberflaeche.md section 2): live
 * search and filters above the list, an opened member beside it.
 *
 * One address, two verbs. GET shows the page from the filters in the URL.
 * POST is what the search form and the links of the list send (htmx): the
 * search text only ever travels in the body, never in a URL - a name does
 * not belong into an address bar, a log or a bookmark (CLAUDE.md section
 * 4). What is not member data - filters, sort order, page, the opened
 * member as an id - stays in the URL, so a link from another page can
 * point here.
 *
 * htmx asks for one of three answers, told apart by the id of the element
 * it swaps (HX-Target): the result list only (typing, filters, sorting,
 * paging), the whole area of list and member (opening a member, the tabs),
 * or - without htmx - the whole page.
 */
final readonly class MitgliederController
{
    public const string PFAD = '/app/mitglieder';

    private const string ZIEL_ERGEBNIS = 'mitglieder-ergebnis';
    private const string ZIEL_BEREICH = 'mitglieder-bereich';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d));
    }

    public function seite(Request $r): Response
    {
        $view = $this->dienste->view;
        $daten = [...$r->query, ...$r->post]; // the body is the more recent state
        $filter = MitgliederFilter::aus($daten);

        $pdo = $this->dienste->pdo();
        $ergebnis = new MitgliederListeRepository($pdo)->seite($filter, new \DateTimeImmutable('today'));
        $filter = $filter->mit(['seite' => $ergebnis->seite]);

        // The opened member is the building block every list page embeds
        // (MitgliedAnsicht, 06 section 5); here it swaps the whole area and
        // posts the search form along, so the search text stays in the body.
        $einbettung = new Einbettung(self::PFAD, $filter->parameter())->mitHtmx(self::ZIEL_BEREICH, '#mitglieder-form');
        $ansicht = MitgliedAnsicht::aus($this->dienste)->oeffnen($r, $einbettung);

        $daten = [
            'title' => 'Mitglieder',
            'filter' => $filter,
            'ergebnis' => $ergebnis,
            'abteilungen' => new AbteilungRepository($pdo)->alle(),
            'beitragsarten' => new BeitragsartRepository($pdo)->alle(),
            'mitglied' => $ansicht->mitglied,
            'ansicht' => $ansicht,
            'einbettung' => $einbettung,
            'url' => self::urlBauer($filter, $ansicht->id(), $ansicht->reiter),
            'formUrl' => self::formUrl($filter, $ansicht->id(), $ansicht->reiter),
            'nurErgebnis' => false,
            'darfAnlegen' => $view->konto()?->darf(Permission::MitgliederBearbeiten) === true,
            'scripts' => ['/js/anschrift.js'],
        ];

        $htmx = $r->istHtmx() && $r->header('hx-history-restore-request') !== 'true';

        return match (true) {
            $htmx && $r->header('hx-target') === self::ZIEL_ERGEBNIS => Response::html($view->fragment(
                'mitglieder/ergebnis',
                [...$daten, 'nurErgebnis' => true],
            )),
            $htmx && $r->header('hx-target') === self::ZIEL_BEREICH => Response::html($view->fragment('mitglieder/bereich', $daten)),
            default => Response::html($view->render('mitglieder/seite', $daten, Area::App)),
        };
    }

    /**
     * Builds the address of this page for the current state with some
     * values changed (null removes one): filters, sort order, page, opened
     * member and tab - never the search text.
     *
     * @return \Closure(array<string, int|string|null>=): string
     */
    private static function urlBauer(MitgliederFilter $filter, ?int $mitgliedId, ?string $reiter): \Closure
    {
        return static function (array $aenderungen = []) use ($filter, $mitgliedId, $reiter): string {
            $parameter = $filter->parameter();
            if ($mitgliedId !== null) {
                $parameter['mitglied'] = (string) $mitgliedId;
            }
            if ($reiter !== null) {
                $parameter['reiter'] = $reiter;
            }
            foreach ($aenderungen as $name => $wert) {
                if ($wert === null) {
                    unset($parameter[$name]);
                } else {
                    $parameter[$name] = (string) $wert;
                }
            }

            return self::PFAD . ($parameter === [] ? '' : '?' . http_build_query($parameter));
        };
    }

    /**
     * The address the search form posts to: the state that is not a field
     * of the form (sort order, opened member, tab). The filters are fields
     * and travel in the body; a change of them starts on page 1.
     */
    private static function formUrl(MitgliederFilter $filter, ?int $mitgliedId, ?string $reiter): string
    {
        $parameter = array_intersect_key($filter->parameter(), ['sortierung' => true]);
        if ($mitgliedId !== null) {
            $parameter['mitglied'] = (string) $mitgliedId;
        }
        if ($reiter !== null) {
            $parameter['reiter'] = $reiter;
        }

        return self::PFAD . ($parameter === [] ? '' : '?' . http_build_query($parameter));
    }
}
