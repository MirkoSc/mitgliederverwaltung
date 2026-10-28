<?php

declare(strict_types=1);

namespace App\Http;

/** Taken over from Vereinsbelege. */
final readonly class Response implements ResponseInterface
{
    /**
     * @param array<string, string> $headers
     * @param list<Cookie> $additionalCookies a second, third, ... cookie
     *        beyond the one $headers['Set-Cookie'] can hold - see withCookie()
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public array $additionalCookies = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, ['Location' => $location]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /** The same response with one more (or a replaced) header. */
    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, [...$this->headers, $name => $value], $this->body, $this->additionalCookies);
    }

    /**
     * The same response plus one more `Set-Cookie`. The first goes into the
     * header map, every further one into $additionalCookies - a header map
     * can hold only one value per name.
     */
    public function withCookie(Cookie $cookie): self
    {
        if (!isset($this->headers['Set-Cookie'])) {
            return new self(
                $this->status,
                [...$this->headers, 'Set-Cookie' => $cookie->header()],
                $this->body,
                $this->additionalCookies,
            );
        }

        return new self($this->status, $this->headers, $this->body, [...$this->additionalCookies, $cookie]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            // A Set-Cookie must never replace: PHP's header() drops every
            // same-named header by default, and that includes the session
            // cookie session_regenerate_id() queued at login - the browser
            // would keep the old, anonymous session id.
            header($name . ': ' . $value, strcasecmp($name, 'Set-Cookie') !== 0);
        }
        foreach ($this->additionalCookies as $cookie) {
            header('Set-Cookie: ' . $cookie->header(), false);
        }
        echo $this->body;
    }
}
