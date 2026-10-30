<?php

declare(strict_types=1);

namespace App\Service\Karte;

/** Status, type and body of one answer of an external service; status 0 = no answer. */
final readonly class HttpAntwort
{
    public function __construct(
        public int $status,
        public string $body = '',
        public string $contentType = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->status === 200;
    }
}
