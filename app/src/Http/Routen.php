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
 * wrapped in the login guard where the declaration asks for a login, a cron
 * route in the check of the cron token, and every other POST in the CSRF
 * check (App\Http\CsrfSchutz) - inside the guard, so an anonymous request
 * gets the login first. A route file cannot register a route without the
 * check its declaration names.
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

    /**
     * The cron of the hoster's control panel calls `/cron?token=…`
     * (docs/spec/01-plattform.md section 5): the token from config.php is
     * its only credential, compared in constant time.
     *
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     * @return \Closure(Request, array<string, string>): ResponseInterface
     */
    private static function cronToken(#[\SensitiveParameter] string $erwartet, \Closure $handler): \Closure
    {
        return static function (Request $request, array $params = []) use ($erwartet, $handler): ResponseInterface {
            $token = $request->query['token'] ?? null;

            return is_string($token) && $token !== '' && hash_equals($erwartet, $token)
                ? $handler($request, $params)
                : new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Zugriff verweigert.');
        };
    }

    private function add(HttpMethod $methode, string $muster, Zugriff $zugriff, \Closure $handler): void
    {
        if ($this->ersatz !== null) {
            $handler = ($this->ersatz)($methode, $muster, $zugriff, $handler);
        }
        if ($zugriff->art === ZugriffArt::Cron) {
            $handler = self::cronToken($this->dienste->config->cronToken, $handler);
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
