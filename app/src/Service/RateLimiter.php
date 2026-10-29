<?php

declare(strict_types=1);

namespace App\Service;

use App\Dienste;
use App\Repository\RateLimitRepository;

/**
 * Fixed-window counter on top of the `rate_limit` table (docs/spec/
 * 02-sicherheit.md section 3), taken over from Vereinsbelege - only the
 * parts this application uses.
 *
 * The key is a purpose plus a value ("login.ip" + address, "login.konto" +
 * e-mail address), hashed before it reaches the database.
 *
 * versuch() counts the attempt BEFORE the secret is checked and answers
 * from the count after its own increment, in one atomic database step: a
 * read-then-write would let any number of parallel requests pass before the
 * first failure was written. A success then clears the counter (reset()),
 * so in the end only failures stay counted.
 */
final readonly class RateLimiter
{
    /** docs/spec/02-sicherheit.md section 3: 15-minute window. */
    public const int FENSTER_SEKUNDEN = 900;

    /** Login attempts per window and IP address. */
    public const int LOGIN_JE_IP = 20;

    /**
     * Per account: lower than per IP (an attacker can rotate addresses, not
     * the account they want), high enough that fumbling one's own password
     * does not lock one out for long.
     */
    public const int LOGIN_JE_KONTO = 10;

    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private RateLimitRepository $zeilen,
        private int $fensterSekunden = self::FENSTER_SEKUNDEN,
    ) {
        if ($this->fensterSekunden < 1) {
            throw new \InvalidArgumentException('The rate limit window must be at least one second.');
        }
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(new RateLimitRepository($d->pdo())));
    }

    /**
     * Counts this attempt and says whether it is still within the limit.
     * An attempt that is turned away still counts - the door stays shut for
     * the rest of the window. An empty value (no IP on the command line) is
     * never limited: it would be one shared counter for everybody.
     *
     * @return bool true when the attempt may go ahead
     */
    public function versuch(string $zweck, string $wert, int $grenze, ?\DateTimeImmutable $now = null): bool
    {
        if ($wert === '') {
            return true;
        }

        return $this->zeilen->hit($this->schluessel($zweck, $wert), $this->fensterBeginn($now ?? new \DateTimeImmutable())) <= $grenze;
    }

    /** Clears the counter - what a successful attempt does. */
    public function reset(string $zweck, string $wert): void
    {
        if ($wert !== '') {
            $this->zeilen->delete($this->schluessel($zweck, $wert));
        }
    }

    /**
     * Drops rows whose window is long gone (App\Service\Cron\
     * AnmeldungCleanupTask): a counter nobody reads is a hashed address kept
     * for nothing.
     */
    public function cleanup(\DateTimeImmutable $now): int
    {
        return $this->zeilen->deleteOlderThan($now->setTimestamp($now->getTimestamp() - 2 * $this->fensterSekunden));
    }

    /** The start of the window the moment falls into - the same for every request inside it. */
    private function fensterBeginn(\DateTimeImmutable $now): string
    {
        $zeit = $now->getTimestamp();

        return $now->setTimestamp($zeit - ($zeit % $this->fensterSekunden))->format(self::FORMAT);
    }

    private function schluessel(string $zweck, string $wert): string
    {
        return hash('sha256', $zweck . ':' . $wert, true);
    }
}
