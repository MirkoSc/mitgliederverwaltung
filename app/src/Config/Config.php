<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Immutable application configuration, loaded from shared/config.php
 * (docs/spec/01-plattform.md section 2). Taken over from Vereinsbelege.
 *
 * Holds the server key (docs/spec/02-sicherheit.md section 2). It must never
 * reach the database, a database backup, a log line or an error page.
 */
final readonly class Config
{
    /** Length of the server key in raw bytes (libsodium secretbox key). */
    public const int SERVER_KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    private function __construct(
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        /** Raw 32 bytes, already base64-decoded. */
        public string $serverKey,
        public string $cronToken,
        public bool $debug,
        /**
         * The previous server key, raw 32 bytes - only while a key change
         * runs (App\Service\Crypto\Schluesselwechsel): everything is still
         * readable under it until every value has been wrapped anew. Null
         * otherwise.
         */
        public ?string $serverKeyAlt = null,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigException(sprintf('Config file not found: %s', $path));
        }

        $data = require $path;
        if (!is_array($data)) {
            throw new ConfigException(sprintf('Config file must return an array: %s', $path));
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dbHost: self::stringValue($data, 'db.host'),
            dbPort: self::intValue($data, 'db.port', 3306),
            dbName: self::stringValue($data, 'db.name'),
            dbUser: self::stringValue($data, 'db.user'),
            dbPassword: self::stringValue($data, 'db.password'),
            serverKey: self::serverKey($data),
            cronToken: self::stringValue($data, 'cron_token'),
            debug: self::boolValue($data, 'debug', false),
            serverKeyAlt: self::lookup($data, 'server_key_alt') === null ? null : self::serverKey($data, 'server_key_alt'),
        );
    }

    /**
     * Keeps the secrets out of var_dump() output in a debug session.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'dbHost' => $this->dbHost,
            'dbPort' => $this->dbPort,
            'dbName' => $this->dbName,
            'dbUser' => $this->dbUser,
            'dbPassword' => '***',
            'serverKey' => '*** server key ***',
            'cronToken' => '***',
            'debug' => $this->debug,
            'serverKeyAlt' => $this->serverKeyAlt === null ? null : '*** old server key ***',
        ];
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->dbHost,
            $this->dbPort,
            $this->dbName,
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringValue(array $data, string $key): string
    {
        $value = self::lookup($data, $key);
        if (!is_string($value) || $value === '') {
            throw new ConfigException(sprintf('Missing or empty config value: %s', $key));
        }

        return $value;
    }

    /**
     * Stored base64 encoded so that config.php stays a readable PHP file;
     * the application works with the raw bytes.
     *
     * @param array<mixed> $data
     */
    private static function serverKey(array $data, string $key = 'server_key'): string
    {
        $raw = base64_decode(self::stringValue($data, $key), true);
        if ($raw === false || strlen($raw) !== self::SERVER_KEY_BYTES) {
            throw new ConfigException(sprintf(
                'Config value %s must be %d base64 encoded bytes.',
                $key,
                self::SERVER_KEY_BYTES,
            ));
        }

        return $raw;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intValue(array $data, string $key, int $default): int
    {
        $value = self::lookup($data, $key) ?? $default;
        if (!is_int($value)) {
            throw new ConfigException(sprintf('Config value must be an integer: %s', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolValue(array $data, string $key, bool $default): bool
    {
        $value = self::lookup($data, $key) ?? $default;
        if (!is_bool($value)) {
            throw new ConfigException(sprintf('Config value must be a boolean: %s', $key));
        }

        return $value;
    }

    /**
     * Looks up a dot-separated key ("db.host") in a nested array.
     *
     * @param array<mixed> $data
     */
    private static function lookup(array $data, string $key): mixed
    {
        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
