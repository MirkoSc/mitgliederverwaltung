<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\MailStatus;
use App\Repository\MailQueueRepository;
use App\Repository\SettingRepository;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\ServerKeyGeheimnisSchutz;
use App\Service\Mail\SmtpSecurity;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeMailTransport;

/** The mail queue and the Mailer against a real database and the fake transport (01 section 6). */
final class MailerTest extends DatabaseTestCase
{
    private FakeMailTransport $transport;

    private MailQueueRepository $queue;

    private MailSettingsRepository $einstellungen;

    private Mailer $mailer;

    private \DateTimeImmutable $jetzt;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->transport = new FakeMailTransport();
        $this->queue = new MailQueueRepository($this->pdo());
        $this->einstellungen = new MailSettingsRepository(
            new SettingRepository($this->pdo()),
            new ServerKeyGeheimnisSchutz(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        );
        $this->mailer = new Mailer(
            $this->queue,
            $this->einstellungen,
            new MailTemplates(dirname(__DIR__, 2) . '/app/views/mail'),
            $this->transport,
        );
        $this->jetzt = new \DateTimeImmutable('2026-03-01 10:00:00');
        $this->konfigurieren();
    }

    private function konfigurieren(): void
    {
        $this->einstellungen->speichern('smtp.example.org', 587, SmtpSecurity::Starttls, 'u', 'p', 'verein@example.org', '', 'Testverein', '');
    }

    public function testATestMailLandsInTheFakeTransport(): void
    {
        $ergebnis = $this->mailer->sendeTestmail('admin@example.org', $this->jetzt);

        self::assertTrue($ergebnis->erfolg);
        self::assertCount(1, $this->transport->gesendet);
        self::assertSame('admin@example.org', $this->transport->gesendet[0]->to);
        self::assertStringContainsString('Testverein', $this->transport->gesendet[0]->body);
        self::assertStringContainsString('01.03.2026 10:00', $this->transport->gesendet[0]->body);
        self::assertSame(MailStatus::Gesendet, $this->queue->find(1)?->status);
    }

    public function testQueuedMailsAreOnlySentByTheCron(): void
    {
        $this->mailer->einreihen('a@example.org', 'Betreff', 'Text', $this->jetzt);
        self::assertSame([], $this->transport->gesendet);

        self::assertSame(1, $this->mailer->sendeFaellige($this->jetzt));
        self::assertCount(1, $this->transport->gesendet);

        // Nothing left: a second run sends nothing again.
        self::assertSame(0, $this->mailer->sendeFaellige($this->jetzt));
        self::assertCount(1, $this->transport->gesendet);
    }

    public function testAFailedImmediateMailStaysQueuedAndTheCronRetriesIt(): void
    {
        $this->transport->fehlschlaege = 1;

        $ergebnis = $this->mailer->sendeSofort('a@example.org', 'Code', 'Text', $this->jetzt);

        self::assertFalse($ergebnis->erfolg);
        self::assertNotNull($ergebnis->fehlermeldung);
        $mail = $this->queue->find(1);
        self::assertSame(MailStatus::Offen, $mail?->status);
        self::assertSame(1, $mail?->attempts);

        // Backoff: not due a few seconds later, due after a minute.
        self::assertSame(0, $this->mailer->sendeFaellige($this->jetzt->modify('+30 seconds')));
        self::assertSame(1, $this->mailer->sendeFaellige($this->jetzt->modify('+61 seconds')));
        self::assertCount(1, $this->transport->gesendet);
    }

    public function testAMailGivesUpAfterTheFifthAttempt(): void
    {
        $this->transport->fehlschlaege = 99;
        $this->mailer->einreihen('a@example.org', 'Betreff', 'Text', $this->jetzt);

        $zeit = $this->jetzt;
        for ($i = 0; $i < 7; $i++) {
            $zeit = $zeit->modify('+1 day');
            $this->mailer->sendeFaellige($zeit);
        }

        $mail = $this->queue->find(1);
        self::assertSame(MailStatus::Fehler, $mail?->status);
        self::assertSame(Mailer::MAX_ATTEMPTS, $mail?->attempts);
        self::assertSame(1, $this->queue->anzahlFehler());
        // The error column holds the exception class and nothing else.
        self::assertSame('App\Service\Mail\MailException', $mail?->lastError);
    }

    public function testIncompleteSettingsFailWithoutTouchingTheTransport(): void
    {
        $this->pdo()->exec('DELETE FROM setting');

        $ergebnis = $this->mailer->sendeSofort('a@example.org', 'Betreff', 'Text', $this->jetzt);

        self::assertFalse($ergebnis->erfolg);
        self::assertSame([], $this->transport->gesendet);
    }

    /** Two cron runs that claim at the same moment must not both send the same mail. */
    public function testAClaimedMailIsNotHandedOutTwice(): void
    {
        $id = $this->queue->enqueue('a@example.org', 'Betreff', 'Text', $this->jetzt);

        self::assertCount(1, $this->queue->claimDue($this->jetzt, 10));
        self::assertCount(0, $this->queue->claimDue($this->jetzt, 10));
        self::assertNull($this->queue->claimById($id, $this->jetzt));
        // A crashed run: after the provisional lock the mail is due again.
        self::assertCount(1, $this->queue->claimDue($this->jetzt->modify('+6 minutes'), 10));
    }

    public function testSentMailsAreCleanedUpAfterAWeekButFailuresStay(): void
    {
        $this->mailer->sendeSofort('a@example.org', 'Betreff', 'Text', $this->jetzt);
        $this->transport->fehlschlaege = 1;
        $zweite = $this->queue->enqueue('b@example.org', 'Betreff', 'Text', $this->jetzt);
        $mail = $this->queue->claimById($zweite, $this->jetzt);
        self::assertNotNull($mail);
        $this->queue->markFailed($zweite, 'X', MailStatus::Fehler, $this->jetzt, $this->jetzt);

        self::assertSame(0, $this->queue->deleteSentBefore($this->jetzt->modify('-1 day')));
        self::assertSame(1, $this->queue->deleteSentBefore($this->jetzt->modify('+1 day')));
        self::assertNotNull($this->queue->find($zweite));
    }
}
