<?php

declare(strict_types=1);

namespace App\Installer;

use App\Config\Config;
use App\Config\ConfigException;

/**
 * Writes shared/config.php - the last step of the installer: its existence
 * is what locks /install (docs/spec/04-installation-und-update.md section 2).
 * Taken over from Vereinsbelege.
 *
 * The file lives in shared/ and survives every update; nothing ever
 * overwrites it again. It holds the server key, which never goes into the
 * database or a backup (docs/spec/02-sicherheit.md section 2).
 */
final class ConfigWriter
{
    /** A new server key, base64 encoded as config.php keeps it. */
    public static function neuerServerSchluessel(): string
    {
        return base64_encode(random_bytes(Config::SERVER_KEY_BYTES));
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string} $db
     * @param string $serverSchluessel base64, as neuerServerSchluessel() returns it
     *
     * @return string the new cron token - every write generates one, a
     *         restore too, so the installer shows the cron URL anew
     */
    public static function schreiben(string $configFile, array $db, #[\SensitiveParameter] string $serverSchluessel): string
    {
        $config = [
            'debug' => false,
            'db' => [
                'host' => $db['host'],
                'port' => $db['port'],
                'name' => $db['name'],
                'user' => $db['user'],
                'password' => $db['password'],
            ],
            'server_key' => $serverSchluessel,
            'cron_token' => bin2hex(random_bytes(24)),
        ];

        self::ablegen($configFile, $config);

        return $config['cron_token'];
    }

    /**
     * The server key and the previous one as config.php holds them right
     * now, raw bytes - read from the file's text with a regular expression,
     * never with include: an opcode cache may hand out the file as it was a
     * moment ago, and the key change must never decide on a stale key.
     *
     * @return array{0: string, 1: ?string} [server_key, server_key_alt]
     *
     * @throws ConfigException when the file or its server_key is unreadable
     */
    public static function schluesselLesen(string $configFile): array
    {
        $inhalt = @file_get_contents($configFile);
        if ($inhalt === false) {
            throw new ConfigException('config.php ist nicht lesbar.');
        }
        $schluessel = self::schluesselAusText($inhalt, 'server_key');
        if ($schluessel === null) {
            throw new ConfigException('config.php enthält keinen gültigen server_key.');
        }

        return [$schluessel, self::schluesselAusText($inhalt, 'server_key_alt')];
    }

    /**
     * Rewrites config.php with another server key and, while a key change
     * runs, the previous one beside it ($alt null removes it). Everything
     * else in the file stays as it is. Atomic like the first write: a
     * temporary file, flushed to disk, 0600, renamed over the old one - at
     * every moment config.php is either the old or the new file, never half
     * of one.
     *
     * @param string $neu raw 32 bytes
     * @param string|null $alt raw 32 bytes or null
     */
    public static function schluesselSchreiben(
        string $configFile,
        #[\SensitiveParameter] string $neu,
        #[\SensitiveParameter] ?string $alt,
    ): void {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configFile, true);
        }
        $config = is_file($configFile) ? (static fn(string $pfad): mixed => require $pfad)($configFile) : null;
        if (!is_array($config)) {
            throw new ConfigException('config.php ist nicht lesbar.');
        }
        $config['server_key'] = base64_encode($neu);
        unset($config['server_key_alt']);
        if ($alt !== null) {
            $config['server_key_alt'] = base64_encode($alt);
        }

        self::ablegen($configFile, $config);

        // What the next request reads must be what was meant - checked on
        // the text, the one thing no cache stands in front of.
        [$gelesen, $gelesenAlt] = self::schluesselLesen($configFile);
        if (!hash_equals($neu, $gelesen) || ($alt === null ? $gelesenAlt !== null : ($gelesenAlt === null || !hash_equals($alt, $gelesenAlt)))) {
            throw new ConfigException('config.php enthält nach dem Schreiben nicht den erwarteten Schlüssel.');
        }
    }

    private static function schluesselAusText(#[\SensitiveParameter] string $inhalt, string $name): ?string
    {
        $muster = "/['\"]" . preg_quote($name, '/') . "['\"]\\s*=>\\s*['\"]([A-Za-z0-9+\\/=]+)['\"]/";
        if (preg_match($muster, $inhalt, $treffer) !== 1) {
            return null;
        }
        $roh = base64_decode($treffer[1], true);

        return $roh !== false && strlen($roh) === Config::SERVER_KEY_BYTES ? $roh : null;
    }

    /**
     * @param array<mixed> $config
     */
    private static function ablegen(string $configFile, array $config): void
    {
        // What the application will read must be readable - checked before
        // the file exists, so a broken value never locks the installer.
        Config::fromArray($config);

        $dir = dirname($configFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Das Verzeichnis shared/ kann nicht angelegt werden.');
        }

        $inhalt = "<?php\n\n"
            . "// Written by the installer. Lives in shared/ and survives updates.\n"
            . "// Holds the server key: without it no backup and no file can be\n"
            . "// decrypted. Keep a copy apart from the backups, never commit it.\n"
            . "// server_key_alt exists only while a key change runs (Einstellungen\n"
            . "// > System); never remove it by hand.\n"
            . 'return ' . var_export($config, true) . ";\n";

        // Written beside and renamed into place: config.php ends the install
        // mode, so a half-written file must never exist under its name.
        $tmp = $configFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $datei = @fopen($tmp, 'xb');
        if ($datei === false) {
            throw new \RuntimeException('config.php kann nicht geschrieben werden: shared/ ist nicht beschreibbar.');
        }
        // The only file in shared/ with secrets. Set before the content
        // arrives; best effort: some hosts ignore it, which is why shared/
        // sits outside the docroot anyway.
        @chmod($tmp, 0600);
        $geschrieben = @fwrite($datei, $inhalt);
        // On disk before the rename, so a crash right after it never leaves
        // an empty config.php behind.
        $ok = $geschrieben === strlen($inhalt) && @fflush($datei) && (!function_exists('fsync') || @fsync($datei));
        fclose($datei);
        if (!$ok || !@rename($tmp, $configFile)) {
            @unlink($tmp);
            throw new \RuntimeException('config.php kann nicht geschrieben werden: shared/ ist nicht beschreibbar.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configFile, true);
        }
    }
}
