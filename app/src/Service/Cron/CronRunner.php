<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Repository\CronSperre;
use App\Repository\SettingRepository;
use App\Support\FileLogger;

/**
 * One run of the cron endpoint (docs/spec/01-plattform.md section 5).
 *
 * The host may call every minute, which raises two problems this class owns:
 *   - runs can overlap -> a database lock; a run that finds it held ends at
 *     once and without an error;
 *   - housekeeping would run 1440 times a day -> the cleanup tasks only run
 *     when the interval has passed since the last time.
 *
 * A failing task is logged and reported by class name; it does not stop the
 * others.
 */
final class CronRunner
{
    public const int DEFAULT_CLEANUP_INTERVAL_SECONDS = 3600;

    public const string SETTING_LAST_CLEANUP = 'cron_letztes_aufraeumen';

    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * @param list<CronTask> $aufgaben
     */
    public function __construct(
        private readonly CronSperre $sperre,
        private readonly SettingRepository $settings,
        private readonly array $aufgaben,
        private readonly ?FileLogger $logger = null,
        private readonly int $aufraeumIntervall = self::DEFAULT_CLEANUP_INTERVAL_SECONDS,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self(
            new CronSperre($dienste->pdo()),
            $dienste->einstellungen(),
            CronAufgaben::alle($dienste),
            $dienste->logger,
        );
    }

    public function run(?\DateTimeImmutable $now = null): CronRunResult
    {
        $now ??= new \DateTimeImmutable();
        $start = hrtime(true);

        if (!$this->sperre->nehmen()) {
            return CronRunResult::alreadyRunning();
        }

        try {
            $jedesMal = array_values(array_filter($this->aufgaben, static fn(CronTask $t): bool => !$t->istAufraeumen()));
            $aufraeumen = array_values(array_filter($this->aufgaben, static fn(CronTask $t): bool => $t->istAufraeumen()));

            $ergebnis = $this->runAll($jedesMal, $now);

            $aufgeraeumt = $aufraeumen !== [] && $this->aufraeumenFaellig($now);
            if ($aufgeraeumt) {
                // Stamped BEFORE the tasks: a task that crashes the run (or a
                // host that cuts it off) must not be retried every minute.
                $this->settings->set(self::SETTING_LAST_CLEANUP, $now->format(self::FORMAT));
                $ergebnis = [...$ergebnis, ...$this->runAll($aufraeumen, $now)];
            }
        } finally {
            $this->sperre->freigeben();
        }

        return CronRunResult::completed($ergebnis, $aufgeraeumt, intdiv(hrtime(true) - $start, 1_000_000));
    }

    private function aufraeumenFaellig(\DateTimeImmutable $now): bool
    {
        $letztes = $this->settings->text(self::SETTING_LAST_CLEANUP);
        if ($letztes === '') {
            return true;
        }

        try {
            $zuletzt = new \DateTimeImmutable($letztes);
        } catch (\Exception) {
            return true;
        }

        return $now->getTimestamp() - $zuletzt->getTimestamp() >= $this->aufraeumIntervall;
    }

    /**
     * @param list<CronTask> $tasks
     * @return list<array{name: string, erledigt?: int, fehler?: string}>
     */
    private function runAll(array $tasks, \DateTimeImmutable $now): array
    {
        $ergebnis = [];
        foreach ($tasks as $task) {
            try {
                $ergebnis[] = ['name' => $task->name(), 'erledigt' => $task->run($now)];
            } catch (\Throwable $e) {
                // Class only: a message can quote data (CLAUDE.md section 4).
                $this->logger?->append(sprintf('Cron task %s failed: %s', $task->name(), $e::class));
                $ergebnis[] = ['name' => $task->name(), 'fehler' => $e::class];
            }
        }

        return $ergebnis;
    }
}
