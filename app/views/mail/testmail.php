<?php

/**
 * Test mail sent from the settings section Mail. Purely technical - no
 * member data (CLAUDE.md section 4). Plain text, rendered by
 * App\Service\Mail\MailTemplates, so there is no e() escaping on purpose.
 *
 * @var string $vereinsname
 * @var \DateTimeImmutable $zeit
 */
?>
Diese Testmail wurde über die Mail-Einstellungen der Mitgliederverwaltung von <?= $vereinsname ?> ausgelöst.

Zeitpunkt: <?= $zeit->format('d.m.Y H:i') ?> Uhr

Wenn diese Mail ankommt, sind Server, Port, Verschlüsselung und Zugangsdaten richtig eingestellt.
