<?php

/**
 * Password reset link (docs/spec/02-sicherheit.md section 3: 32 bytes,
 * only the hash stored, 30 minutes, one use). Purely technical - no member
 * data (CLAUDE.md section 4).
 *
 * Plain text, rendered by App\Service\Mail\MailTemplates, so there is no
 * e() escaping on purpose. $link is built from the checked base URL
 * (App\Service\Mail\PublicUrl) and a hex token - nothing user-typed.
 *
 * @var string $link
 * @var int $gueltigMinuten
 * @var string $vereinsname
 */
?>
Für Ihr Konto in der Mitgliederverwaltung von <?= $vereinsname ?> wurde ein neues Passwort angefordert.

Über diesen Link legen Sie ein neues Passwort fest:

<?= $link ?>


Der Link ist <?= $gueltigMinuten ?> Minuten gültig und kann nur einmal verwendet werden.
Danach sind Sie auf allen Geräten abgemeldet.

Kennen Sie Ihr Passwort doch noch, ignorieren Sie diese Mail einfach - dann ändert sich nichts.
