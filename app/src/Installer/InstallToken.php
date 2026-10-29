<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * The installation token (docs/spec/04-installation-und-update.md section 2,
 * taken over from Vereinsbelege): while shared/config.php is missing,
 * /install answers without a login - whoever reaches it first could
 * otherwise install with a database and an admin of their own. The token
 * proves that the visitor either just ran setup.php (which writes the file
 * and hands the token over in its redirect) or can read shared/ by FTP.
 *
 * 128 random bits as 32 hex characters in shared/install_token.txt (0600,
 * shared/ is locked by its .htaccess). The session remembers only a hash of
 * the token it was released with, so a session released for an older token
 * is worthless once the file changes. The file goes when config.php is
 * written (InstallController).
 */
final readonly class InstallToken
{
    /** $_SESSION key: sha256 of the token this session was released with. */
    public const string SESSION_KEY = 'install_freigabe';

    public function __construct(private string $file)
    {
    }

    /** The current token; created when there is none (an install without setup.php). */
    public function ensure(): string
    {
        $token = $this->current();
        if ($token !== null) {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Verzeichnis für das Installations-Token fehlt.');
        }
        if (@file_put_contents($this->file, $token . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Installations-Token nicht beschreibbar.');
        }
        @chmod($this->file, 0600);

        return $token;
    }

    /**
     * The token in the file, null when there is no valid one. Besides the
     * 32 hex characters written here, 16 to 64 letters and digits are
     * accepted: where PHP runs as another user than FTP, the admin cannot
     * read the generated file, but can delete it and upload one with a token
     * of their own. Case does not count.
     */
    public function current(): ?string
    {
        if (!is_file($this->file)) {
            return null;
        }
        $token = strtolower(trim((string) @file_get_contents($this->file, length: 256)));

        return preg_match('/^[0-9a-z]{16,64}$/D', $token) === 1 ? $token : null;
    }

    /** Whether $eingabe is the current token (spaces and case ignored). */
    public function matches(#[\SensitiveParameter] string $eingabe): bool
    {
        $token = $this->current();
        $eingabe = strtolower((string) preg_replace('/\s+/', '', $eingabe));

        return $token !== null && hash_equals($token, $eingabe);
    }

    /**
     * Marks the running session as released for the current token. A new
     * session id first, as at a login: an id planted before is not the one
     * that carries the release.
     */
    public function release(): void
    {
        $token = $this->current();
        if ($token === null) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_KEY] = hash('sha256', $token);
    }

    /** Whether the running session was released for the current token. */
    public function isReleased(): bool
    {
        $token = $this->current();
        $freigabe = $_SESSION[self::SESSION_KEY] ?? null;

        return $token !== null && is_string($freigabe) && hash_equals(hash('sha256', $token), $freigabe);
    }
}
