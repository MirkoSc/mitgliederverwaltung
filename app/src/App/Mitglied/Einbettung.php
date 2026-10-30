<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Http\Request;
use App\View\Navigation;

/**
 * Where an opened member is shown (docs/spec/06-oberflaeche.md section 5):
 * the page that embeds the member view (App\App\Mitglied\MitgliedAnsicht)
 * beside its list - the members page, a list, the families.
 *
 * It knows the address of that page with its own state (filters, sort
 * order - never a search text, CLAUDE.md section 4) and builds every
 * address the member view needs from it: a tab, "Schließen", the member
 * after a save. So nothing in the member leads to another page.
 *
 * Forms of the member post to their own routes (/app/mitglieder/{id}/...);
 * they carry the page in a hidden field `zurueck` (feld()), pages of their
 * own (choose a family, the question before deleting) in the query
 * (mitRueckweg()). The routes read it back with ausAnfrage() and redirect
 * there - only to an address of this application (no open redirect).
 * Without the field it is the members page, as before.
 *
 * With htmx (ziel set) a link of the member swaps the element with that id
 * as a whole (outerHTML) and keeps the address in the history; with a form
 * selector it posts and includes that form (the members page: the search
 * text travels in the body), else it gets. Without ziel the links are
 * ordinary links.
 */
final readonly class Einbettung
{
    /** The member's own parameters; the page's state never uses these names. */
    private const array EIGENE = ['mitglied', 'reiter', 'zurueck', 'fokus'];

    /**
     * @param string $pfad the page, e.g. /app/listen/geburtstage
     * @param array<string, string> $parameter the page's own state, without mitglied and reiter
     * @param string|null $ziel id of the element htmx swaps (outerHTML), null = ordinary links
     * @param string|null $formular selector of a form htmx posts along, null = htmx gets
     * @param string|null $zurueckText the way back as a link of its own, also on the PC,
     *                                 in place of "Schließen" (the families: "Zurück zur
     *                                 Familie"); null = "Zurück zur Liste" on a phone,
     *                                 "Schließen" on the PC
     */
    public function __construct(
        public string $pfad,
        public array $parameter = [],
        public ?string $ziel = null,
        public ?string $formular = null,
        public ?string $zurueckText = null,
    ) {
        if (!self::istPfad($pfad)) {
            throw new \InvalidArgumentException('Not an address of this application: ' . $pfad);
        }
    }

    /** The members page without filters: where a member opens when nothing else is said. */
    public static function mitgliederSeite(): self
    {
        return new self(Navigation::MITGLIEDER);
    }

    /**
     * The page a request came from: the field `zurueck` (body, then query),
     * when it is a safe address of this application; else the members page.
     */
    public static function ausAnfrage(Request $r): self
    {
        return self::ausAdresse($r->feld('zurueck')) ?? self::mitgliederSeite();
    }

    /**
     * An address of this application as an embedding (without htmx);
     * null when it is not one. Its own mitglied and reiter are dropped.
     */
    public static function ausAdresse(string $adresse): ?self
    {
        if (!self::istSicher($adresse)) {
            return null;
        }
        $teile = explode('?', $adresse, 2);
        parse_str($teile[1] ?? '', $roh);
        $parameter = [];
        foreach ($roh as $name => $wert) {
            if (is_string($name) && is_string($wert) && !in_array($name, self::EIGENE, true)) {
                $parameter[$name] = $wert;
            }
        }

        return new self($teile[0], $parameter);
    }

    /**
     * Whether an address may be a target of a redirect: a path below /app
     * of this application, with a plain query - nothing that leaves the
     * site (no scheme, no //, no backslash, no ..).
     */
    public static function istSicher(string $adresse): bool
    {
        $teile = explode('?', $adresse, 2);

        return self::istPfad($teile[0])
            && (!isset($teile[1]) || preg_match('/^[A-Za-z0-9_=&%.+\-]*$/D', $teile[1]) === 1);
    }

    private static function istPfad(string $pfad): bool
    {
        return preg_match('#^/app(/[a-z0-9][a-z0-9\-]*)*$#D', $pfad) === 1;
    }

    /** The same page, its links swapping the element $ziel by htmx. */
    public function mitHtmx(string $ziel, ?string $formular = null): self
    {
        return new self($this->pfad, $this->parameter, $ziel, $formular, $this->zurueckText);
    }

    /**
     * The same page, the member led back from by a link of its own with
     * this text (on the PC too) - for a page where the member stands in
     * place of something else, not beside the list (the families: the
     * member replaces the family).
     */
    public function mitZurueck(string $text): self
    {
        return new self($this->pfad, $this->parameter, $this->ziel, $this->formular, $text);
    }

    /**
     * The page with a member opened on a tab (null: the first tab), or
     * without a member; $aenderungen changes the page's own state (null
     * removes a value).
     *
     * @param array<string, int|string|null> $aenderungen
     */
    public function adresse(?int $mitgliedId = null, ?string $reiter = null, array $aenderungen = []): string
    {
        $parameter = $this->parameter;
        foreach ($aenderungen as $name => $wert) {
            if ($wert === null) {
                unset($parameter[$name]);
            } else {
                $parameter[$name] = (string) $wert;
            }
        }
        if ($mitgliedId !== null) {
            $parameter['mitglied'] = (string) $mitgliedId;
            if ($reiter !== null) {
                $parameter['reiter'] = $reiter;
            }
        }

        return $this->pfad . ($parameter === [] ? '' : '?' . http_build_query($parameter));
    }

    /** The page without a member: "Schließen", and where a deleted member leads. */
    public function rueckweg(): string
    {
        return $this->adresse();
    }

    /** Whether this is the members page without any state - the default needs no field. */
    public function istStandard(): bool
    {
        return $this->rueckweg() === Navigation::MITGLIEDER;
    }

    /**
     * The hidden field for a form of the member (HTML); nothing on the
     * plain members page, which is the default anyway.
     */
    public function feld(): string
    {
        return $this->istStandard() ? '' : '<input type="hidden" name="zurueck" value="' . e($this->rueckweg()) . '">';
    }

    /** A page of its own (choose a family, delete) that leads back here. */
    public function mitRueckweg(string $adresse): string
    {
        return $this->istStandard() ? $adresse : $adresse . (str_contains($adresse, '?') ? '&' : '?') . http_build_query(['zurueck' => $this->rueckweg()]);
    }

    /**
     * The attributes of a link to $adresse (HTML, escaped): with htmx it
     * swaps the embedding element and pushes the address. $fokus tells the
     * answer where the keyboard focus goes after the swap ('mitglied' =
     * the heading of the member, 'reiter' = the chosen tab); a link
     * without it (sorting the list) leaves the focus alone.
     */
    public function link(string $adresse, ?string $fokus = null, string $swap = 'outerHTML show:window:top'): string
    {
        $ziel = e($adresse);
        if ($this->ziel === null) {
            return 'href="' . $ziel . '"';
        }
        $html = 'href="' . $ziel . '" ' . ($this->formular === null ? 'hx-get' : 'hx-post') . '="' . $ziel . '"';
        if ($this->formular !== null) {
            $html .= ' hx-include="' . e($this->formular) . '"';
        }
        if ($fokus !== null) {
            $html .= " hx-vals='" . e((string) json_encode(['fokus' => $fokus])) . "'";
        }

        return $html . ' hx-target="#' . e($this->ziel) . '" hx-swap="' . e($swap) . '" hx-push-url="' . $ziel . '"';
    }
}
