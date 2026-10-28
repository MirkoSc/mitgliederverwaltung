<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Permission;

/**
 * The access declaration every route carries (docs/spec/02-sicherheit.md
 * section 6, CLAUDE.md section 4): who may call it. Router::add() takes it
 * as a required argument, so a route without one does not exist, and
 * App\Http\Routen derives the guard wrapper from this very value - the
 * declaration and the check cannot drift apart. Taken over from
 * Vereinsbelege.
 *
 * tests/Http/RoutenZugriffTest.php runs every declared route as anonymous
 * visitor and as each role, and compares all declarations with the reviewed
 * list in tests/Http/routen-zugriff/.
 */
final readonly class Zugriff
{
    private function __construct(
        public ZugriffArt $art,
        public ?Permission $recht = null,
        /** JSON routes (fetch()): 401/403 as JSON instead of a redirect or a page. */
        public bool $api = false,
    ) {
    }

    /**
     * No login: the way in (login, password reset) and the legal pages
     * (docs/ENTSCHEIDUNGEN.md E-F3) - nothing else.
     */
    public static function oeffentlich(): self
    {
        return new self(ZugriffArt::Oeffentlich);
    }

    /** No session; the cron token is the credential (01 section 5). */
    public static function cron(): self
    {
        return new self(ZugriffArt::Cron);
    }

    /** Any logged-in account - things every account does for itself. */
    public static function angemeldet(): self
    {
        return new self(ZugriffArt::Angemeldet);
    }

    public static function recht(Permission $recht): self
    {
        return new self(ZugriffArt::Recht, $recht);
    }

    public function alsApi(): self
    {
        return new self($this->art, $this->recht, api: true);
    }

    /** Whether the login guard has to run at all. */
    public function brauchtAnmeldung(): bool
    {
        return match ($this->art) {
            ZugriffArt::Oeffentlich, ZugriffArt::Cron => false,
            ZugriffArt::Angemeldet, ZugriffArt::Recht => true,
        };
    }

    /** For the reviewed list in tests/Http/routen-zugriff/ and test output. */
    public function beschreibung(): string
    {
        return match ($this->art) {
            ZugriffArt::Oeffentlich => 'öffentlich',
            ZugriffArt::Cron => 'cron-token',
            ZugriffArt::Angemeldet => 'angemeldet',
            ZugriffArt::Recht => (string) $this->recht?->value,
        } . ($this->api ? ' (api)' : '');
    }
}
