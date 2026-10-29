<?php

declare(strict_types=1);

namespace App\App;

use App\App\Mitglied\MitgliedErweiterungen;
use App\Dienste;
use App\Domain\Mitglied;
use App\Domain\MitgliederFilter;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\MitgliederListeRepository;
use App\Repository\MitgliedRepository;
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
        $heute = new \DateTimeImmutable('today');
        $ergebnis = new MitgliederListeRepository($pdo)->seite($filter, $heute);
        $filter = $filter->mit(['seite' => $ergebnis->seite]);

        $mitgliedId = self::id($daten['mitglied'] ?? null);
        $mitglied = $mitgliedId === null ? null : new MitgliedRepository($pdo)->finde($mitgliedId);
        $reiterWunsch = is_string($daten['reiter'] ?? null) ? $daten['reiter'] : '';

        $abteilungen = new AbteilungRepository($pdo)->alle();
        $beitragsarten = new BeitragsartRepository($pdo)->alle();

        $daten = [
            'title' => 'Mitglieder',
            'filter' => $filter,
            'ergebnis' => $ergebnis,
            'abteilungen' => $abteilungen,
            'beitragsarten' => $beitragsarten,
            'mitglied' => $mitglied,
            // An unknown member is not an error page: the list stays usable.
            'mitgliedUnbekannt' => $mitgliedId !== null && $mitglied === null,
            'url' => self::urlBauer($filter, $mitglied?->id, null),
            'formUrl' => self::formUrl($filter, $mitglied?->id, null),
            'nurErgebnis' => false,
            'darfAnlegen' => $this->dienste->view->konto()?->darf(Permission::MitgliederBearbeiten) === true,
        ];
        if ($mitglied !== null) {
            $daten = [...$daten, ...$this->detail($mitglied, $reiterWunsch, $filter, $heute, $daten)];
        }

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
     * What the opened member shows: sections on top, then its tabs with the
     * chosen one (found by file name, 05 section 6). Nobody has written a
     * tab yet while this page is built - the template then shows a short
     * read-only summary instead.
     *
     * @param array<string, mixed> $seite
     * @return array<string, mixed>
     */
    private function detail(Mitglied $mitglied, string $reiterWunsch, MitgliederFilter $filter, \DateTimeImmutable $heute, array $seite): array
    {
        $konto = $this->dienste->view->konto();
        $id = (int) $mitglied->id;

        $reiter = MitgliedErweiterungen::reiter()->alle($this->dienste, $konto);
        $gewaehlt = null;
        foreach ($reiter as $kandidat) {
            if ($kandidat->schluessel() === $reiterWunsch) {
                $gewaehlt = $kandidat;
            }
        }
        $gewaehlt ??= $reiter[0] ?? null;

        $abschnitte = [];
        foreach (MitgliedErweiterungen::abschnitte()->alle($this->dienste, $konto) as $abschnitt) {
            $abschnitte[] = $this->dienste->view->fragment($abschnitt->template(), $abschnitt->daten($id));
        }

        $ehemalig = $mitglied->istEhemalig($heute);

        return [
            'reiter' => $reiter,
            'gewaehlterReiter' => $gewaehlt?->schluessel(),
            'reiterInhalt' => $gewaehlt === null
                ? null
                : $this->dienste->view->fragment($gewaehlt->template(), $gewaehlt->daten($id)),
            'abschnitte' => $abschnitte,
            'statusText' => $ehemalig ? 'ehemalig' : ($mitglied->aktiv ? 'aktiv' : 'passiv'),
            'statusKlasse' => $ehemalig ? 'etikett-fehler' : ($mitglied->aktiv ? 'etikett-ok' : ''),
            'url' => self::urlBauer($filter, $id, $gewaehlt?->schluessel()),
            'formUrl' => self::formUrl($filter, $id, $gewaehlt?->schluessel()),
        ];
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

    private static function id(mixed $wert): ?int
    {
        return is_string($wert) && preg_match('/^[1-9][0-9]{0,17}$/D', $wert) === 1 ? (int) $wert : null;
    }
}
