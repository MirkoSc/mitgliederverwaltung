<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

// A cron route as issue #7 adds one - for RoutenDateienTest.
return static function (Routen $routen): void {
    $routen->get('/cron', Zugriff::cron(), static fn(Request $r): Response => Response::html('cron gelaufen'));
};
