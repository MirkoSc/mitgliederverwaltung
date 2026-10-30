<?php

declare(strict_types=1);

use App\App\KarteController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Navigation;

/**
 * The page "Karte" (issue #91): both roles see it (mitglieder.sehen), and
 * the map tiles come through this installation under the same right - a
 * visitor without login gets no tile. "Jetzt Koordinaten ermitteln" and
 * "Alle neu ermitteln" (issue #108) are the admin's (admin.system: they talk
 * to an external service on demand).
 */
return static function (Routen $routen): void {
    $sehen = Zugriff::recht(Permission::MitgliederSehen);
    $controller = static fn(): KarteController => KarteController::aus($routen->dienste());

    $routen->get(Navigation::KARTE, $sehen, static fn(Request $r): Response => $controller()->seite($r));
    $routen->get(
        KarteController::PFAD_KACHEL . '/{z:[0-9]+}/{x:[0-9]+}/{y:[0-9]+}.png',
        $sehen,
        static fn(Request $r, array $p): Response => $controller()->kachel($p['z'], $p['x'], $p['y']),
    );
    $routen->post(KarteController::PFAD_ERMITTELN, Zugriff::recht(Permission::AdminSystem), static fn(Request $r): Response => $controller()->ermitteln());
    $routen->post(KarteController::PFAD_NEU_ERMITTELN, Zugriff::recht(Permission::AdminSystem), static fn(Request $r): Response => $controller()->neuErmitteln($r));
};
