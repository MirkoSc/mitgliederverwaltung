<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\JobStatus;
use App\Repository\JobRepository;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/** Step chains (docs/spec/01-plattform.md section 4). */
final class JobRepositoryTest extends DatabaseTestCase
{
    private JobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->jobs = new JobRepository($this->pdo());
    }

    public function testANewJobWaitsWithItsState(): void
    {
        $id = $this->jobs->anlegen('import', 3, ['zeile' => 0]);

        $job = $this->jobs->finde($id);

        self::assertNotNull($job);
        self::assertSame('import', $job->typ);
        self::assertSame(JobStatus::Offen, $job->status);
        self::assertSame(3, $job->userId);
        self::assertSame(['zeile' => 0], $job->state);
        self::assertSame(0, $job->progress);
        self::assertNull($job->lockedUntil);
        self::assertNull($this->jobs->finde($id + 1));
    }

    /** Two tabs, one step: only one of them gets the lock. */
    public function testOnlyOneRequestGetsTheLock(): void
    {
        $id = $this->jobs->anlegen('import', null);
        $jetzt = new \DateTimeImmutable('2026-10-01 12:00:00');

        self::assertNotNull($this->jobs->sperren($id, 30, $jetzt));
        self::assertNull($this->jobs->sperren($id, 30, $jetzt->modify('+10 seconds')));
        self::assertSame(JobStatus::Laeuft, $this->jobs->finde($id)?->status);
    }

    /** A step that died half way: its lock runs out and the step is repeated. */
    public function testAnExpiredLockCanBeTakenAgain(): void
    {
        $id = $this->jobs->anlegen('import', null);
        $jetzt = new \DateTimeImmutable('2026-10-01 12:00:00');
        $this->jobs->sperren($id, 30, $jetzt);

        self::assertNotNull($this->jobs->sperren($id, 30, $jetzt->modify('+31 seconds')));
    }

    public function testSavingAStepReleasesTheLockAndKeepsThePosition(): void
    {
        $id = $this->jobs->anlegen('import', null, ['zeile' => 0]);
        $sperre = $this->jobs->sperren($id, 30);
        self::assertNotNull($sperre);

        self::assertTrue($this->jobs->schrittSpeichern($sperre, ['zeile' => 100], 40));

        $job = $this->jobs->finde($id);
        self::assertSame(['zeile' => 100], $job?->state);
        self::assertSame(40, $job->progress);
        self::assertNull($job->lockedUntil);
        self::assertNotNull($this->jobs->sperren($id, 30), 'the next step may start at once');
    }

    /**
     * A step that outlived its lock must not overwrite the position of the
     * step that took over - nor release that step's lock.
     */
    public function testAStepThatLostItsLockWritesNothing(): void
    {
        $id = $this->jobs->anlegen('import', null, ['zeile' => 0]);
        $jetzt = new \DateTimeImmutable('2026-10-01 12:00:00');
        $langsam = $this->jobs->sperren($id, 30, $jetzt);
        $uebernommen = $this->jobs->sperren($id, 30, $jetzt->modify('+31 seconds'));
        self::assertNotNull($langsam);
        self::assertNotNull($uebernommen);

        self::assertTrue($this->jobs->schrittSpeichern($uebernommen, ['zeile' => 200], 80));
        self::assertNotNull($this->jobs->sperren($id, 30, $jetzt->modify('+40 seconds')));
        $neu = $this->jobs->finde($id);

        self::assertFalse($this->jobs->schrittSpeichern($langsam, ['zeile' => 100], 40));
        self::assertFalse($this->jobs->abschliessen($langsam, []));
        self::assertFalse($this->jobs->fehlschlagen($langsam, new \RuntimeException('zu spät')));
        self::assertEquals($neu, $this->jobs->finde($id), 'position and lock of the newer step are untouched');
    }

    public function testAFinishedJobIsNeverTakenAgain(): void
    {
        $id = $this->jobs->anlegen('import', null);
        $sperre = $this->jobs->sperren($id, 30);
        self::assertNotNull($sperre);

        self::assertTrue($this->jobs->abschliessen($sperre, ['zeilen' => 250]));

        $job = $this->jobs->finde($id);
        self::assertSame(JobStatus::Fertig, $job?->status);
        self::assertSame(['zeilen' => 250], $job->state);
        self::assertSame(100, $job->progress);
        self::assertNull($this->jobs->sperren($id, 30));
    }

    /** Only the class: a database message would quote row data (CLAUDE.md section 4). */
    public function testAFailedJobKeepsTheExceptionClassOnly(): void
    {
        $id = $this->jobs->anlegen('import', null);
        $sperre = $this->jobs->sperren($id, 30);
        self::assertNotNull($sperre);

        $this->jobs->fehlschlagen($sperre, new \RuntimeException('Zeile von Max Muster kaputt'));

        $job = $this->jobs->finde($id);
        self::assertSame(JobStatus::Fehler, $job?->status);
        self::assertSame(\RuntimeException::class, $job->lastError);
        self::assertNull($this->jobs->sperren($id, 30));
    }

    public function testProgressStaysBetweenZeroAndHundred(): void
    {
        $id = $this->jobs->anlegen('import', null);
        $sperre = $this->jobs->sperren($id, 30);
        self::assertNotNull($sperre);

        $this->jobs->schrittSpeichern($sperre, [], 250);

        self::assertSame(100, $this->jobs->finde($id)?->progress);
    }
}
