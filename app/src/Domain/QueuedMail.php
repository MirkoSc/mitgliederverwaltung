<?php

declare(strict_types=1);

namespace App\Domain;

/** One row of the `mail_queue` table. */
final readonly class QueuedMail
{
    public function __construct(
        public int $id,
        public string $to,
        public string $subject,
        public string $body,
        public MailStatus $status,
        public int $attempts,
        public \DateTimeImmutable $nextTryAt,
        public ?string $lastError,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
