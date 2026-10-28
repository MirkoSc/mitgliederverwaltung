<?php

declare(strict_types=1);

namespace App\Http;

use App\Dienste;

/**
 * What the route files under app/routes/ register their routes with
 * (docs/spec/05-entwicklung.md section 6: one file per area, all files are
 * loaded - no central route table that every issue would have to edit).
 *
 * Every route names its access declaration (App\Http\Zugriff), and this is
 * the one place that turns the declaration into the check: the handler is
 * wrapped in the login guard where the declaration asks for a login, and
 * every POST additionally in the CSRF check (App\Http\CsrfSchutz) - inside
 * the guard, so an anonymous request gets the login first. A route file
 * cannot register a route without either.
 *
 * A route file returns `static function (Routen $routen): void`; it builds
 * its controllers lazily inside the handlers from $routen->dienste(), so a
 * request pays only for the route it hits.
 */
final class Routen
{
    /**
     * @param (\Closure(HttpMethod, string, Zugriff, \Closure): \Closure)|null $ersatz
     *        tests only: replaces each handler before it is wrapped - the
     *        access matrix test puts a stand-in behind every route and checks
     *        what the real guard lets through
     */
    public function __construct(
        private readonly Router $router,
        private readonly Dienste $dienste,
        private readonly LoginGuard $guard,
        private readonly CsrfSchutz $csrf,
        private readonly ?\Closure $ersatz = null,
    ) {
    }

    public function dienste(): Dienste
    {
        return $this->dienste;
    }

    /**
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     */
    public function get(string $muster, Zugriff $zugriff, \Closure $handler): void
    {
        $this->add(HttpMethod::Get, $muster, $zugriff, $handler);
    }

    /**
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     */
    public function post(string $muster, Zugriff $zugriff, \Closure $handler): void
    {
        $this->add(HttpMethod::Post, $muster, $zugriff, $handler);
    }

    /**
     * Loads every route file of a directory, in name order.
     */
    public function ladeDateien(string $verzeichnis): void
    {
        $dateien = glob($verzeichnis . '/*.php') ?: [];
        sort($dateien, SORT_STRING);

        foreach ($dateien as $datei) {
            $registrieren = require $datei;
            if (!$registrieren instanceof \Closure) {
                throw new \LogicException(sprintf('Route file %s must return a Closure(Routen): void.', basename($datei)));
            }
            $registrieren($this);
        }
    }

    private function add(HttpMethod $methode, string $muster, Zugriff $zugriff, \Closure $handler): void
    {
        if ($this->ersatz !== null) {
            $handler = ($this->ersatz)($methode, $muster, $zugriff, $handler);
        }
        if (CsrfSchutz::noetig($methode, $zugriff)) {
            $handler = $this->csrf->pruefe($zugriff->api, $handler);
        }
        if ($zugriff->brauchtAnmeldung()) {
            $handler = $this->guard->pruefe($zugriff, $handler);
        }

        $this->router->add($methode, $muster, $zugriff, $handler);
    }
}
