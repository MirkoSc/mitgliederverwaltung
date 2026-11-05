<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Http\Request;
use App\Http\Response;
use App\Repository\RateLimitRepository;
use App\Service\Karte\Anschriftvorschlaege;
use App\Service\RateLimiter;

/**
 * Address suggestions for the member form (issue #127,
 * public/js/anschrift-vorschlaege.js): the typed street (plus postcode and
 * town as context) comes in the POST body - never in the URL, where it
 * would end up in access logs - and the suggestions go back as JSON.
 *
 * At most JE_MINUTE requests per account and minute (App\Service\
 * RateLimiter on the table rate_limit, window of one minute): the script
 * waits for a pause in typing, so only a runaway client gets there - and
 * the external service stays out of it.
 */
final readonly class AnschriftController
{
    public const string PFAD = '/app/anschrift/vorschlaege';

    public const int JE_MINUTE = 60;

    private const string LIMIT = 'anschrift.vorschlaege';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d));
    }

    public function vorschlaege(Request $r): Response
    {
        $limits = new RateLimiter(new RateLimitRepository($this->dienste->pdo()), 60);
        if (!$limits->versuch(self::LIMIT, (string) $this->dienste->session->userId(), self::JE_MINUTE)) {
            return Response::json(['vorschlaege' => []], 429);
        }

        return Response::json([
            'vorschlaege' => Anschriftvorschlaege::aus($this->dienste)->suchen($r->feld('strasse'), $r->feld('plz'), $r->feld('ort')),
        ]);
    }
}
