<?php

declare(strict_types=1);

namespace App\Service\Karte;

use App\Dienste;
use App\Service\Mail\PublicUrl;

/**
 * The shared pieces of the map's services (issue #91): the HTTP client -
 * one per request, replaced by a fake in the tests through
 * Dienste::dienst(HttpAbruf::class, …) - and the User-Agent both
 * OpenStreetMap services want: the application, its version and the
 * address of this installation (setting `oeffentliche_url`, section Mail).
 */
final class KartenDienste
{
    /** Contact when no public address is set: the project itself. */
    public const string PROJEKT_URL = 'https://github.com/MirkoSc/mitgliederverwaltung';

    public static function http(Dienste $dienste): HttpAbruf
    {
        return $dienste->dienst(HttpAbruf::class, static fn(): HttpAbruf => new CurlHttpAbruf());
    }

    public static function userAgent(Dienste $dienste): string
    {
        $url = '';
        try {
            $url = PublicUrl::normalize($dienste->einstellungen()->text('oeffentliche_url')) ?? '';
        } catch (\Throwable) {
            // no database, no address - the project URL stands in
        }

        return self::userAgentFuer($dienste->version->value, $url);
    }

    public static function userAgentFuer(string $version, string $installation): string
    {
        return sprintf('Mitgliederverwaltung/%s (+%s)', $version, $installation !== '' ? $installation : self::PROJEKT_URL);
    }
}
