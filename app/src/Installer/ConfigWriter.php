<?php

declare(strict_types=1);

namespace App\Installer;

use App\Config\Config;

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
            . 'return ' . var_export($config, true) . ";\n";

        // Written beside and renamed into place: config.php ends the install
        // mode, so a half-written file must never exist under its name.
        $tmp = $configFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $inhalt, LOCK_EX) === false) {
            throw new \RuntimeException('config.php kann nicht geschrieben werden: shared/ ist nicht beschreibbar.');
        }
        // The only file in shared/ with secrets. Best effort: some hosts
        // ignore it, which is why shared/ sits outside the docroot anyway.
        @chmod($tmp, 0600);
        if (!@rename($tmp, $configFile)) {
            @unlink($tmp);
            throw new \RuntimeException('config.php kann nicht geschrieben werden: shared/ ist nicht beschreibbar.');
        }

        return $config['cron_token'];
    }
}
