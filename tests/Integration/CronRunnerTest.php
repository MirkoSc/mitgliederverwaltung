<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dienste;
use App\Repository\CronSperre;
use App\Repository\SettingRepository;
use App\Service\Cron\CronRunner;
use App\Service\Cron\CronTask;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/** The cron run: lock against overlapping runs, throttled housekeeping, isolated task failures (01 section 5). */
final class CronRunnerTest extends DatabaseTestCase
{
    private int $laeufe = 0;

    private int $aufraeumLaeufe = 0;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
    }

    private function task(string $name, bool $aufraeumen, ?\Closure $aktion = null): CronTask
    {
        return new class ($name, $aufraeumen, $aktion ?? static fn(): int => 1) implements CronTask {
            public function __construct(private string $name, private bool $aufraeumen, private \Closure $aktion)
            {
            }

            public static function erzeugen(Dienste $dienste): self
            {
                throw new \LogicException('not used');
            }

            public function name(): string
            {
                return $this->name;
            }

            public function istAufraeumen(): bool
            {
                return $this->aufraeumen;
            }

            public function run(\DateTimeImmutable $now): int
            {
                return ($this->aktion)();
            }
        };
    }

    /** @param list<CronTask> $aufgaben */
    private function runner(array $aufgaben, ?\PDO $pdo = null): CronRunner
    {
        $pdo ??= $this->pdo();

        return new CronRunner(new CronSperre($pdo), new SettingRepository($pdo), $aufgaben);
    }

    private function zweiteVerbindung(): \PDO
    {
        /** @var array{host: string, port: int, name: string, user: string, password: string} $db */
        $db = self::configData()['db'];

        return new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
            $db['user'],
            $db['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    public function testACronRunDoesNotRunTwiceAtTheSameTime(): void
    {
        $inneres = null;
        $zweiteRunner = $this->runner([], $this->zweiteVerbindung());
        $aufgabe = $this->task('lang', false, function () use ($zweiteRunner, &$inneres): int {
            $this->laeufe++;
            // While this run is working, the next minute's call arrives.
            $inneres = $zweiteRunner->run();

            return 1;
        });

        $aeusseres = $this->runner([$aufgabe])->run();

        self::assertFalse($aeusseres->laeuftBereits);
        self::assertNotNull($inneres);
        self::assertTrue($inneres->laeuftBereits);
        self::assertSame('laeuft_bereits', $inneres->toArray()['status']);
        self::assertSame(1, $this->laeufe);
    }

    public function testTheLockIsFreeAgainAfterTheRun(): void
    {
        $runner = $this->runner([$this->task('a', false)]);

        self::assertFalse($runner->run()->laeuftBereits);
        self::assertFalse($runner->run()->laeuftBereits);
    }

    public function testTheLockIsFreeAfterATaskCrashedTheRun(): void
    {
        // A task failure is caught; the lock has to be released all the same.
        $runner = $this->runner([$this->task('kaputt', false, static fn(): int => throw new \RuntimeException('x'))]);

        $ergebnis = $runner->run();

        self::assertTrue($ergebnis->hasFailures());
        self::assertFalse($runner->run()->laeuftBereits);
    }

    public function testHousekeepingIsThrottled(): void
    {
        $runner = $this->runner([
            $this->task('queue', false, function (): int {
                $this->laeufe++;

                return 0;
            }),
            $this->task('putzen', true, function (): int {
                $this->aufraeumLaeufe++;

                return 0;
            }),
        ]);
        $start = new \DateTimeImmutable('2026-03-01 10:00:00');

        self::assertTrue($runner->run($start)->aufgeraeumt);
        self::assertFalse($runner->run($start->modify('+1 minute'))->aufgeraeumt);
        self::assertFalse($runner->run($start->modify('+59 minutes'))->aufgeraeumt);
        self::assertTrue($runner->run($start->modify('+60 minutes'))->aufgeraeumt);

        self::assertSame(4, $this->laeufe, 'the mail queue runs on every call');
        self::assertSame(2, $this->aufraeumLaeufe);
    }

    public function testAFailingTaskDoesNotStopTheOthersAndOnlyTheClassIsReported(): void
    {
        $runner = $this->runner([
            $this->task('kaputt', false, static fn(): int => throw new \RuntimeException('geheim@example.org')),
            $this->task('gut', false, static fn(): int => 3),
        ]);

        $antwort = $runner->run()->toArray();

        self::assertSame('fehler', $antwort['status']);
        self::assertSame([['name' => 'kaputt', 'fehler' => \RuntimeException::class], ['name' => 'gut', 'erledigt' => 3]], $antwort['aufgaben']);
        self::assertStringNotContainsString('geheim', json_encode($antwort, JSON_THROW_ON_ERROR));
    }
}
