<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

/**
 * One hint about a member (docs/spec/07-mitglieder.md section 6): computed,
 * never stored, never blocking. The text says what looks wrong and what to
 * do; it holds no personal data (the member is shown beside it).
 */
final readonly class Hinweis
{
    public function __construct(
        public HinweisArt $art,
        public string $text,
    ) {
    }
}
