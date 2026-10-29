<?php

/**
 * The code of the second factor (docs/spec/02-sicherheit.md section 3: six
 * digits, 10 minutes, at most 5 tries). Purely technical - no member data
 * (CLAUDE.md section 4).
 *
 * Plain text, rendered by App\Service\Mail\MailTemplates, so there is no
 * e() escaping on purpose. $code is six digits made by the server.
 *
 * @var string $code
 * @var int $gueltigMinuten
 * @var string $vereinsname
 */
?>
Ihr Code für die Anmeldung in der Mitgliederverwaltung von <?= $vereinsname ?>:

<?= $code ?>


Der Code ist <?= $gueltigMinuten ?> Minuten gültig und gilt nur für eine Anmeldung.

Haben Sie sich nicht gerade angemeldet, kennt jemand anderes Ihr Passwort.
Ändern Sie es dann bitte sofort und sagen Sie dem Admin des Vereins Bescheid.
