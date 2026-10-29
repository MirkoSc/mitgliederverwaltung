<?php

declare(strict_types=1);

use App\App\MitgliederController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Navigation;

/**
 * The members page (docs/spec/06-oberflaeche.md section 2, issue #18): live
 * search, filters, the list and the opened member beside it. Issue #19 adds
 * the member itself as tabs (App\App\Mitglied\Reiter) without a route of
 * its own - an opened member is a state of this page.
 *
 * GET shows the page from the URL; POST is the same page for the search
 * form and the links of the list, because the search text must not travel
 * in a URL (CLAUDE.md section 4). Both need the right to see members.
 */
return static function (Routen $routen): void {
    $zugriff = Zugriff::recht(Permission::MitgliederSehen);
    $seite = static fn(Request $r): Response => MitgliederController::aus($routen->dienste())->seite($r);

    $routen->get(Navigation::MITGLIEDER, $zugriff, $seite);
    $routen->post(Navigation::MITGLIEDER, $zugriff, $seite);
};
