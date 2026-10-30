<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Dienste;
use App\Http\Request;
use App\Repository\MitgliedRepository;

/**
 * The opened member as a building block (docs/spec/06-oberflaeche.md
 * section 5, issue #86): heading, hints, tabs with the chosen one - the
 * right half of the pattern "Liste neben Mitglied". Every page with a
 * list of persons embeds it the same way; the members page is one of them.
 *
 * The embedding page says where it is (Einbettung); everything inside the
 * member - tabs, "Schließen", the hint link, forms and the redirect after a
 * save - leads back to that page, never to another one. Rights stay where
 * they are: a tab or section is only there for whoever has its right, and
 * every route behind it checks its own.
 */
final readonly class MitgliedAnsicht
{
    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d));
    }

    /**
     * The member the request asks for: `mitglied` (id) and `reiter` from
     * the query or - more recent - the body, as the page's links send them.
     */
    public function oeffnen(Request $r, Einbettung $einbettung): GeoeffnetesMitglied
    {
        $daten = [...$r->query, ...$r->post];
        $reiter = is_string($daten['reiter'] ?? null) ? $daten['reiter'] : '';

        $fokus = self::fokus($r, $daten, $this->dienste->session->hatFlash());

        return $this->zeigen(self::id($daten['mitglied'] ?? null), $reiter, $fokus, $einbettung);
    }

    /**
     * @param string|null $fokus where the keyboard focus goes: 'mitglied' (the heading),
     *                           'reiter' (the chosen tab) or null (stays where it is)
     */
    public function zeigen(?int $mitgliedId, string $reiterWunsch, ?string $fokus, Einbettung $einbettung): GeoeffnetesMitglied
    {
        $mitglied = $mitgliedId === null ? null : new MitgliedRepository($this->dienste->pdo())->finde($mitgliedId);
        $view = $this->dienste->view;
        if ($mitglied === null) {
            return new GeoeffnetesMitglied(null, $mitgliedId !== null, null, $view->fragment('mitglied/ansicht', ['mitglied' => null]));
        }

        $konto = $view->konto();
        $id = (int) $mitglied->id;
        $reiter = MitgliedErweiterungen::reiter()->alle($this->dienste, $konto);
        $gewaehlt = null;
        foreach ($reiter as $kandidat) {
            if ($kandidat->schluessel() === $reiterWunsch) {
                $gewaehlt = $kandidat;
            }
        }
        $gewaehlt ??= $reiter[0] ?? null;
        $offen = $gewaehlt?->schluessel();

        // What every tab and section gets besides its own data: where the
        // member is shown, so its links and forms lead back there.
        $gemeinsam = ['einbettung' => $einbettung, 'mitgliedId' => $id, 'offenerReiter' => $offen];
        $abschnitte = [];
        foreach (MitgliedErweiterungen::abschnitte()->alle($this->dienste, $konto) as $abschnitt) {
            $abschnitte[] = $view->fragment($abschnitt->template(), [...$abschnitt->daten($id), ...$gemeinsam]);
        }
        $ehemalig = $mitglied->istEhemalig(new \DateTimeImmutable('today'));

        $html = $view->fragment('mitglied/ansicht', [
            'mitglied' => $mitglied,
            'einbettung' => $einbettung,
            'reiter' => $reiter,
            'gewaehlterReiter' => $offen,
            'reiterInhalt' => $gewaehlt === null ? null : $view->fragment($gewaehlt->template(), [...$gewaehlt->daten($id), ...$gemeinsam]),
            'abschnitte' => $abschnitte,
            'statusText' => $ehemalig ? 'ehemalig' : ($mitglied->aktiv ? 'aktiv' : 'passiv'),
            'statusKlasse' => $ehemalig ? 'etikett-fehler' : ($mitglied->aktiv ? 'etikett-ok' : ''),
            'fokus' => $fokus,
        ]);

        return new GeoeffnetesMitglied($mitglied, false, $offen, $html);
    }

    /** A member id from a parameter; null for anything else. */
    public static function id(mixed $wert): ?int
    {
        return is_string($wert) && preg_match('/^[1-9][0-9]{0,17}$/D', $wert) === 1 ? (int) $wert : null;
    }

    /**
     * Where the focus goes: a whole page that shows a member (opened by a
     * link without script) puts it on the member's heading - unless a
     * message waits at the top of the page (after a save): that stays in
     * view, the focus at the start. An htmx answer moves it only where its
     * link asked for it (Einbettung::link()), so sorting the list beside it
     * does not move the focus.
     *
     * @param array<array-key, mixed> $daten
     */
    private static function fokus(Request $r, array $daten, bool $meldungWartet): ?string
    {
        if ($r->header('hx-history-restore-request') === 'true') {
            return null;
        }
        if (!$r->istHtmx()) {
            return $meldungWartet ? null : 'mitglied';
        }
        $fokus = $daten['fokus'] ?? null;

        return $fokus === 'mitglied' || $fokus === 'reiter' ? $fokus : null;
    }
}
