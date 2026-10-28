<?php

declare(strict_types=1);

namespace App;

use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Http\Entwicklungskonten;
use App\Http\Kontoquelle;
use App\Http\Session;
use App\Repository\JobRepository;
use App\Repository\SettingRepository;
use App\Service\Audit\AuditLog;
use App\Support\FileLogger;
use App\Support\Version;
use App\View\View;

/**
 * The services of one request, built lazily - what the route files under
 * app/routes/ and the extensions (settings sections, lists, member tabs)
 * build their controllers from, so that a new area needs no central wiring
 * (docs/spec/05-entwicklung.md section 6).
 *
 * Deliberately small: the basics every area needs live here as methods; a
 * service of one area is built by that area itself, shared across the
 * request through dienst():
 *
 *     public static function aus(Dienste $d): self
 *     {
 *         return $d->dienst(self::class, static fn(Dienste $d): self => new self($d->pdo()));
 *     }
 *
 * Nothing opens a database connection before a matched route asks for one.
 */
final class Dienste
{
    /** @var array<string, object> */
    private array $dienste = [];

    private ?ConnectionFactory $verbindungen = null;

    /**
     * @param (\Closure(): \PDO)|null $pdoFabrik tests only: their own
     *        connection instead of the one from config.php
     */
    public function __construct(
        public readonly Paths $paths,
        public readonly Config $config,
        public readonly Version $version,
        public readonly View $view,
        public readonly Session $session,
        public readonly FileLogger $logger,
        private readonly ?\Closure $pdoFabrik = null,
        private ?Kontoquelle $kontoquelle = null,
    ) {
    }

    /** The connection for the next statement (App\Database\ConnectionFactory). */
    public function pdo(): \PDO
    {
        return $this->pdoFabrik !== null ? ($this->pdoFabrik)() : $this->verbindungen()->pdo();
    }

    public function verbindungen(): ConnectionFactory
    {
        return $this->verbindungen ??= new ConnectionFactory($this->config);
    }

    public function einstellungen(): SettingRepository
    {
        return new SettingRepository($this->pdo());
    }

    public function jobs(): JobRepository
    {
        return new JobRepository($this->pdo());
    }

    /** The audit log (docs/spec/02-sicherheit.md section 8); every area records through it. */
    public function audit(): AuditLog
    {
        return $this->dienst(AuditLog::class, fn(self $d): AuditLog => new AuditLog($d->pdo(), $d->config->serverKey));
    }

    /**
     * Where the login guard looks up accounts. Until the user table exists
     * (issue #6) the two made-up development accounts - switched on only
     * with `debug` in config.php and a development version.
     */
    public function kontoquelle(): Kontoquelle
    {
        return $this->kontoquelle ??= new Entwicklungskonten($this->config->debug && $this->version->isDev());
    }

    /**
     * One shared instance per request of a service an area builds itself.
     *
     * @template T of object
     * @param class-string<T> $klasse
     * @param \Closure(self): T $fabrik
     * @return T
     */
    public function dienst(string $klasse, \Closure $fabrik): object
    {
        $dienst = $this->dienste[$klasse] ??= $fabrik($this);
        if (!$dienst instanceof $klasse) {
            throw new \LogicException(sprintf('The factory for %s built something else.', $klasse));
        }

        return $dienst;
    }
}
