<?php

/**
 * Security notice (docs/spec/02-sicherheit.md section 3: "Sicherheitsmails:
 * neues Gerät, Passwort geändert/zurückgesetzt, 2FA geändert"). $ereignis is
 * one of the fixed sentences of App\Service\Account\Kontomails, never user
 * input - no member data, like every mail template (CLAUDE.md section 4).
 *
 * Plain text, rendered by App\Service\Mail\MailTemplates, so there is no
 * e() escaping on purpose.
 *
 * @var string $ereignis
 * @var string $vereinsname
 */
?>
Sicherheitshinweis zu Ihrem Konto in der Mitgliederverwaltung von <?= $vereinsname ?>:

<?= $ereignis ?>


Haben Sie das nicht selbst veranlasst, wenden Sie sich bitte sofort an den Admin des Vereins.
