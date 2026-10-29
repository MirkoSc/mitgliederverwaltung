<?php

/**
 * Invitation link (docs/spec/02-sicherheit.md section 5). Purely
 * procedural - the link, its lifetime, what comes next; no member data
 * (CLAUDE.md section 4), not even who invited or which role: a mail can be
 * forwarded.
 *
 * Plain text, rendered by App\Service\Mail\MailTemplates, so there is no
 * e() escaping on purpose. $link is built from the checked base URL
 * (App\Service\Mail\PublicUrl) and a hex token - nothing user-typed.
 *
 * @var string $link
 * @var int $gueltigStunden
 * @var string $vereinsname
 */
?>
Sie wurden zur Mitgliederverwaltung von <?= $vereinsname ?> eingeladen.

Über diesen Link legen Sie Ihr Passwort fest:

<?= $link ?>


Der Link ist <?= $gueltigStunden ?> Stunden gültig und kann nur einmal verwendet werden.

Erwarten Sie diese Einladung nicht, ignorieren Sie die Mail einfach.
