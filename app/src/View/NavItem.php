<?php

declare(strict_types=1);

namespace App\View;

use App\Domain\Konto;
use App\Domain\Permission;
use App\Http\HttpMethod;

/**
 * One entry of the navigation or the user menu (App\View\Navigation).
 *
 * An entry whose route is not registered yet renders as inactive text
 * instead of a link - the navigation is complete from the start
 * (docs/spec/05-entwicklung.md section 6), and an issue that adds the route
 * in its own file under app/routes/ lights the entry up without touching
 * a central file.
 */
final readonly class NavItem
{
    public function __construct(
        public string $label,
        public string $href,
        /**
         * The right the page behind the entry needs; null for pages every
         * logged-in account may open. Hiding the entry is courtesy - the
         * route checks the same right itself (App\Http\LoginGuard).
         */
        public ?Permission $recht = null,
        /** POST entries render as a small form with the CSRF token. */
        public HttpMethod $methode = HttpMethod::Get,
    ) {
    }

    public function sichtbarFuer(Konto $konto): bool
    {
        return $this->recht === null || $konto->darf($this->recht);
    }

    /**
     * True for the page currently shown - the layout turns that into
     * aria-current="page". Sub-pages count as the same entry.
     */
    public function istAktiv(string $pfad): bool
    {
        if ($this->methode !== HttpMethod::Get) {
            return false;
        }

        return $pfad === $this->href || str_starts_with($pfad, rtrim($this->href, '/') . '/');
    }
}
