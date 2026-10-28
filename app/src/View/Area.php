<?php

declare(strict_types=1);

namespace App\View;

/**
 * The three areas of the application (CLAUDE.md section 3). They share one
 * layout; the area decides the chrome: the public pages carry no navigation
 * and never a CSRF token for htmx (they may have no session at all), the
 * user area and the admin area carry the navigation of
 * App\View\Navigation.
 */
enum Area: string
{
    /** Without an account: login, password reset, legal pages, errors. */
    case Oeffentlich = 'oeffentlich';

    /** Everything fachliche behind the login (/app/...). */
    case App = 'app';

    /** Administration (/admin/...). */
    case Admin = 'admin';

    /** Whether pages of this area show the navigation. */
    public function mitNavigation(): bool
    {
        return $this !== self::Oeffentlich;
    }
}
