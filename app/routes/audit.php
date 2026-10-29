<?php

declare(strict_types=1);

use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\Repository\BenutzerRepository;
use App\View\Area;

/**
 * The audit log page (docs/spec/02-sicherheit.md section 8): the newest
 * entries and the result of the chain check. Admin only.
 */
return static function (Routen $routen): void {
    $routen->get('/admin/audit', Zugriff::recht(Permission::AdminAudit), static function (Request $r) use ($routen): Response {
        $dienste = $routen->dienste();
        $audit = $dienste->audit();
        // Names instead of bare ids; a deleted user stays a number.
        $namen = [];
        foreach (new BenutzerRepository($dienste->pdo())->alle() as $benutzer) {
            $namen[$benutzer->id] = $benutzer->name;
        }

        return Response::html($dienste->view->render('admin/audit', [
            'title' => 'Audit-Log',
            'pruefung' => $audit->pruefen(),
            'eintraege' => $audit->neueste(100),
            'anzahl' => $audit->anzahl(),
            'benutzerNamen' => $namen,
        ], Area::Admin));
    });
};
