<?php

declare(strict_types=1);

namespace App\Http;

/**
 * One `Set-Cookie` header, as a value. Taken over from Vereinsbelege,
 * without the vault key.
 *
 * Not `setcookie()`: a cookie that belongs to a response should travel with
 * that response (Response::withCookie()) instead of being written into the
 * output buffer from somewhere in the middle of a controller - and a value
 * object is the only form of this that a test can read back.
 *
 * Over HTTPS a cookie that guards something carries the `__Host-` prefix:
 * the browser accepts it only with Secure, Path=/ and no Domain, and in
 * exchange no other host can set or overwrite it. hostPrefixed() builds that
 * and falls back to the plain name over HTTP (the docker development setup),
 * where the prefix would make the browser drop the cookie.
 */
final readonly class Cookie
{
    /**
     * @param int|null $maxAge null = a session cookie; 0 deletes
     */
    public function __construct(
        public string $name,
        public string $value,
        public bool $secure,
        public ?int $maxAge = null,
        public string $sameSite = 'Lax',
        public string $path = '/',
        public bool $httpOnly = true,
    ) {
        if (!preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $this->name)) {
            throw new \InvalidArgumentException('Invalid cookie name.');
        }
        if (!preg_match('/^[A-Za-z0-9!#$%&\'()*+\-.\/:<=>?@\[\]^_`{|}~]*$/', $this->value)) {
            throw new \InvalidArgumentException('Invalid cookie value.');
        }
    }

    /**
     * `__Host-<name>` over HTTPS, `<name>` over plain HTTP.
     */
    public static function hostPrefixed(
        string $name,
        #[\SensitiveParameter] string $value,
        bool $secure,
        ?int $maxAge = null,
        string $sameSite = 'Lax',
    ): self {
        return new self(
            name: $secure ? '__Host-' . $name : $name,
            value: $value,
            secure: $secure,
            maxAge: $maxAge,
            sameSite: $sameSite,
        );
    }

    /**
     * A cookie set by hostPrefixed(), whichever of the two names it uses.
     * Reading both is safe: a browser only ever sends `__Host-` cookies that
     * came over HTTPS.
     */
    public static function hostPrefixedFrom(Request $request, string $name): ?string
    {
        return $request->cookie('__Host-' . $name) ?? $request->cookie($name);
    }

    /**
     * The same cookie, emptied and expired. Name and attributes have to
     * match the original, or the browser keeps the cookie it already has.
     */
    public function expired(): self
    {
        return new self(
            name: $this->name,
            value: '',
            secure: $this->secure,
            maxAge: 0,
            sameSite: $this->sameSite,
            path: $this->path,
            httpOnly: $this->httpOnly,
        );
    }

    public function header(): string
    {
        $teile = [$this->name . '=' . $this->value, 'Path=' . $this->path];

        if ($this->maxAge !== null) {
            $teile[] = 'Max-Age=' . $this->maxAge;
            // Expires next to Max-Age: a cookie that is being deleted has to
            // go away in the browsers that ignore Max-Age as well.
            $teile[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', time() + $this->maxAge);
        }
        if ($this->httpOnly) {
            $teile[] = 'HttpOnly';
        }
        if ($this->secure) {
            $teile[] = 'Secure';
        }
        $teile[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $teile);
    }
}
