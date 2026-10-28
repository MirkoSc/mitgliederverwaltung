<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\FileLogger;
use App\View\Area;
use App\View\View;

/**
 * Request -> Response pipeline: static assets first, then routing, and the
 * one place every unhandled error ends up (docs/spec/01-plattform.md
 * sections 3 and 7). Taken over from Vereinsbelege.
 */
final class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly StaticFileHandler $staticFiles,
        private readonly View $view,
        private readonly bool $debug = false,
        private readonly ?FileLogger $logger = null,
    ) {
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): ResponseInterface
    {
        // The layout marks the current navigation entry with
        // aria-current="page"; every response passes through here, so no
        // controller has to remember to pass its own path.
        $this->view->setCurrentPath($request->path);

        try {
            return $this->staticFiles->tryServe($request) ?? $this->dispatch($request);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $request);
        }
    }

    private function dispatch(Request $request): ResponseInterface
    {
        $match = $this->router->match($request->method, $request->path);

        return match ($match->type) {
            MatchType::Matched => ($match->handler)($request, $match->params),
            MatchType::NotFound => Response::html(
                // Area::Oeffentlich: a 404 can hit any URL, including one
                // that never had a session, so its layout must not need one.
                $this->view->render('fehler', [
                    'title' => 'Seite nicht gefunden',
                    'meldung' => 'Diese Adresse gibt es nicht. Prüfen Sie den Link oder gehen Sie zur Startseite.',
                ], Area::Oeffentlich),
                404,
            ),
            MatchType::MethodNotAllowed => new Response(
                405,
                [
                    'Allow' => implode(', ', array_map(
                        static fn(HttpMethod $m): string => $m->value,
                        $match->allowedMethods,
                    )),
                    'Content-Type' => 'text/plain; charset=utf-8',
                ],
                'Methode nicht erlaubt',
            ),
        };
    }

    private function errorResponse(\Throwable $e, Request $request): ResponseInterface
    {
        // The reference ties the page the user sees to the log line - the
        // page itself shows nothing else (01 section 7).
        $referenz = strtoupper(bin2hex(random_bytes(4)));

        // Structured log WITHOUT the full exception string: a stack trace
        // carries function arguments when zend.exception_ignore_args is off,
        // and that means the plaintext password from a login path or member
        // data from a form. Bootstrap forces the ini setting on and the
        // docroot ships a .user.ini as a net before that; logging class,
        // message and request context only keeps the guarantee even if both
        // were to fail. PDO exception messages never contain bound values,
        // so getMessage() is safe here - and exception messages never carry
        // member data by convention (01 section 7).
        $zeile = sprintf(
            '[%s] %s: %s [%s %s] at %s:%d',
            $referenz,
            $e::class,
            $e->getMessage(),
            $request->method->value,
            $request->path,
            $e->getFile(),
            $e->getLine(),
        );

        error_log($zeile);
        // Additionally into shared/var/log/: error_log() goes to the host's
        // PHP log, which is not reliably readable from the customer panel.
        $this->logger?->append($zeile);

        // Full trace only in debug mode (dev), never in production.
        if ($this->debug) {
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], (string) $e);
        }

        try {
            return Response::html($this->view->render('fehler', [
                'title' => 'Interner Fehler',
                'meldung' => 'Das hat nicht geklappt. Bitte versuchen Sie es in einigen Minuten noch einmal. '
                    . 'Wenn der Fehler bleibt, nennen Sie dem Admin die Referenz.',
                'referenz' => $referenz,
            ], Area::Oeffentlich), 500);
        } catch (\Throwable) {
            // The error page itself failed (a broken template, a missing
            // file) - the plain answer still carries the reference.
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Interner Fehler. Referenz: ' . $referenz);
        }
    }
}
