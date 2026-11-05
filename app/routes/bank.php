<?php

declare(strict_types=1);

use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\Support\Banken;

/**
 * Bank name and BIC for a Bankleitzahl (docs/spec/07-mitglieder.md
 * section 1), asked by the member form (public/js/bankverbindung.js) once
 * the browser has checked a German IBAN. Only the BLZ is sent - it names a
 * bank, not a member - so the IBAN never leaves the form before it is
 * saved, and nothing here is logged. For whoever edits members.
 *
 * Answer: {"bank": name|null, "bic": BIC|null}; both null for an unknown BLZ.
 */
return static function (Routen $routen): void {
    $routen->get(
        Banken::PFAD . '/{blz:[0-9]+}',
        Zugriff::recht(Permission::MitgliederBearbeiten)->alsApi(),
        static function (Request $r, array $p): Response {
            $bank = Banken::fuerBlz((string) $p['blz']);

            return Response::json(['bank' => $bank?->name, 'bic' => $bank?->bic]);
        },
    );
};
