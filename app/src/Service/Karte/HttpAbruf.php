<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * One GET to an external service (geocoder, tile server). Only the server
 * talks to them - the browser never does (issue #91, CSP 'self'). Swapped
 * for a fake in every test: no test ever sends a real request.
 */
interface HttpAbruf
{
    /**
     * Never throws: a network failure is status 0.
     *
     * @param string $userAgent identifies the installation, as the usage
     *        policies of the OpenStreetMap services require
     */
    public function holen(string $url, string $userAgent): HttpAntwort;
}
