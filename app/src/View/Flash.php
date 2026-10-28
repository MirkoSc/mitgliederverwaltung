<?php

declare(strict_types=1);

namespace App\View;

/**
 * A one-off message shown after a redirect (post/redirect/get). Taken over
 * from Vereinsbelege.
 *
 * Never member data: flashes live in the session store on the webspace
 * (CLAUDE.md section 4). "Mitglied gespeichert." yes, "Max Muster
 * gespeichert." no.
 */
final readonly class Flash
{
    public function __construct(
        public string $text,
        public FlashArt $art = FlashArt::Ok,
    ) {
    }
}
