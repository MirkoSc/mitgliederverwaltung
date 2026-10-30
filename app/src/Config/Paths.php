<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Resolves all filesystem paths relative to the release root
 * (docs/spec/01-plattform.md section 2):
 *
 *   /web      docroot shim
 *   /current  active release = release root
 *   /shared   persistent data, sibling of the release root
 *
 * The docker setup mirrors this layout exactly.
 */
final readonly class Paths
{
    public function __construct(public string $releaseRoot)
    {
    }

    public function sharedDir(): string
    {
        return dirname($this->releaseRoot) . '/shared';
    }

    public function configFile(): string
    {
        return $this->sharedDir() . '/config.php';
    }

    public function publicDir(): string
    {
        return $this->releaseRoot . '/public';
    }

    public function viewsDir(): string
    {
        return $this->releaseRoot . '/app/views';
    }

    /** One file per area, each registering its own routes (05 section 6). */
    public function routesDir(): string
    {
        return $this->releaseRoot . '/app/routes';
    }

    public function migrationsDir(): string
    {
        return $this->releaseRoot . '/migrations';
    }

    /** Small, dependency-free data files shipped in the release. */
    public function dataDir(): string
    {
        return $this->releaseRoot . '/app/data';
    }

    public function versionFile(): string
    {
        return $this->releaseRoot . '/VERSION';
    }

    /** The docroot, sibling of the release root: shim, .htaccess, .user.ini. */
    public function webDir(): string
    {
        return dirname($this->releaseRoot) . '/web';
    }

    /**
     * Set while the updater switches releases; checked by the docroot shim,
     * which is why it sits directly in shared/ and not below var/.
     */
    public function maintenanceFlagFile(): string
    {
        return $this->sharedDir() . '/maintenance.flag';
    }

    /**
     * The installation token (docs/spec/04-installation-und-update.md
     * section 2): written by setup.php, deleted once config.php exists.
     */
    public function installTokenFile(): string
    {
        return $this->sharedDir() . '/install_token.txt';
    }

    /**
     * The release channel chosen in setup.php, handed to the installer -
     * there is no database yet that could hold the setting.
     */
    public function setupChannelFile(): string
    {
        return $this->sharedDir() . '/setup_kanal.txt';
    }

    /** State of the update step chain (a file: it must survive the switch). */
    public function updateStateFile(): string
    {
        return $this->sharedDir() . '/update_state.json';
    }

    public function varDir(): string
    {
        return $this->sharedDir() . '/var';
    }

    public function logFile(): string
    {
        return $this->varDir() . '/log/app.log';
    }

    /** Storage backend "fs": encrypted blobs, never plaintext. */
    public function blobDir(): string
    {
        return $this->varDir() . '/blobs';
    }

    public function backupDir(): string
    {
        return $this->varDir() . '/backups';
    }

    /** Only ciphertext or upload chunks; cleaned up by the cron. */
    public function tmpDir(): string
    {
        return $this->varDir() . '/tmp';
    }

    /** The club logo: public, so unencrypted (docs/spec/11-vereinsdaten.md). */
    public function logoDir(): string
    {
        return $this->varDir() . '/logo';
    }

    /**
     * Cache of the map tiles (issue #91): public OpenStreetMap images, no
     * member data; expiry and size cap by the cleanup.
     */
    public function kachelDir(): string
    {
        return $this->varDir() . '/kacheln';
    }

    /** Download area of the updater. */
    public function updateDir(): string
    {
        return $this->varDir() . '/update';
    }
}
