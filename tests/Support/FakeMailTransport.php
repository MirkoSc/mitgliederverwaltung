<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Mail\MailException;
use App\Service\Mail\MailMessage;
use App\Service\Mail\MailSettings;
use App\Service\Mail\MailTransport;

/** The "Fake-Transport" of docs/spec/01-plattform.md: collects mails instead of sending them. */
final class FakeMailTransport implements MailTransport
{
    /** @var list<MailMessage> */
    public array $gesendet = [];

    /** How many of the next attempts fail before one succeeds. */
    public int $fehlschlaege = 0;

    public function send(MailMessage $mail, MailSettings $settings): void
    {
        if ($this->fehlschlaege > 0) {
            $this->fehlschlaege--;

            throw new MailException('Der SMTP-Schritt „Test" ist fehlgeschlagen (Code 451).');
        }
        $this->gesendet[] = $mail;
    }
}
