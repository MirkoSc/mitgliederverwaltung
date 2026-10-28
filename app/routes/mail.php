<?php

declare(strict_types=1);

use App\Admin\MailController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The forms of the settings section Mail (App\Admin\Einstellungen\
 * MailAbschnitt): save the settings, send a test mail. Admin only.
 */
return static function (Routen $routen): void {
    $routen->post('/admin/mail', Zugriff::recht(Permission::AdminEinstellungen), static fn(Request $r): Response => MailController::aus($routen->dienste())->speichern($r));
    $routen->post('/admin/mail/test', Zugriff::recht(Permission::AdminEinstellungen), static fn(Request $r): Response => MailController::aus($routen->dienste())->testmail($r));
};
