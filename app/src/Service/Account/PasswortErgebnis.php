<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * The outcome of setting a password - by invitation, reset or own change:
 * either the messages that say what to fix, or the account and its new
 * `session_epoch` (for the one session that should live on).
 */
final readonly class PasswortErgebnis
{
    /**
     * @param list<string> $fehler
     */
    private function __construct(
        public array $fehler,
        public ?int $userId,
        public int $sessionEpoch,
    ) {
    }

    public static function erfolg(int $userId, int $sessionEpoch): self
    {
        return new self([], $userId, $sessionEpoch);
    }

    public static function fehler(string ...$fehler): self
    {
        return new self(array_values($fehler), null, 0);
    }

    /** @phpstan-assert-if-true !null $this->userId */
    public function istErfolg(): bool
    {
        return $this->fehler === [] && $this->userId !== null;
    }
}
