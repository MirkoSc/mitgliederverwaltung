<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Work whose duration must not show in the response time
 * (docs/spec/01-plattform.md section 3): it runs once the response is out.
 *
 * On the host PHP runs as PHP-FPM (docs/hosting-befunde.md), and there
 * fastcgi_finish_request() hands the finished response to the client while
 * the script goes on. The work is registered as a shutdown function, so it
 * runs after public/index.php has sent the response. The session is
 * written and its lock released first - otherwise a second request of the
 * same browser would wait for the work, and its waiting time would give the
 * work away just the same.
 *
 * Without fastcgi_finish_request() (the CLI of the tests, mod_php in the
 * docker setup) a response cannot end early: the work then runs at once,
 * inside the request.
 */
final class NachDerAntwort
{
    public static function ausfuehren(\Closure $arbeit): void
    {
        if (!\function_exists('fastcgi_finish_request')) {
            $arbeit();

            return;
        }

        register_shutdown_function(static function () use ($arbeit): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            fastcgi_finish_request();
            $arbeit();
        });
    }
}
