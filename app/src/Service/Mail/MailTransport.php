<?php

declare(strict_types=1);

namespace App\Service\Mail;

/** Sends one mail: App\Service\Mail\SmtpTransport in production, a fake in tests. */
interface MailTransport
{
    /**
     * @throws MailException on any failure - the message never contains a
     *         credential, a recipient address or a subject, so it can be
     *         shown to an admin and written to the queue (CLAUDE.md section 4)
     */
    public function send(MailMessage $mail, MailSettings $settings): void;
}
