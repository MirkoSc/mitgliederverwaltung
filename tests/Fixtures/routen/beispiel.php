<?php

declare(strict_types=1);

use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

// A route file as an issue would add it - for RoutenDateienTest.
return static function (Routen $routen): void {
    $routen->get('/app/beispiel', Zugriff::recht(Permission::MitgliederSehen), static fn(Request $r): Response => Response::html('Beispielseite'));
    $routen->post('/app/beispiel', Zugriff::recht(Permission::MitgliederBearbeiten), static fn(Request $r): Response => Response::html('gespeichert'));
};
