<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\Service\Cron\CronRunner;

/**
 * The cron of the hoster's control panel calls `/cron?token=…`
 * (docs/spec/01-plattform.md section 5). The token is checked centrally
 * (App\Http\Routen); the answer carries task names and counts only.
 */
return static function (Routen $routen): void {
    $routen->get('/cron', Zugriff::cron(), static function (Request $r) use ($routen): Response {
        return Response::json(CronRunner::aus($routen->dienste())->run()->toArray());
    });
};
