<?php

/**
 * Fragment: changing the server key (issue #80, App\Service\Crypto\
 * Schluesselwechsel). While a change runs, the fragment asks for the next
 * step itself (hx-trigger="load"), one short request each - and every page
 * view continues an interrupted change the same way.
 *
 * @var array<string, mixed>|null $stand
 * @var bool $laeuft a change runs (Schluesselwechsel::offen())
 * @var bool $weiter the next step is requested on its own
 * @var bool $verzoegert ... after a pause (another tab holds the step)
 * @var int $wartezeit seconds until the final pass may begin
 * @var bool $fertig the last change is finished
 * @var bool $unbestaetigt ... and its new key not yet confirmed as printed
 * @var ?string $fehler
 * @var ?string $schluessel the new key (base64) - in the answer of the step
 *      that finished, and on request with the password until it is printed
 */

use App\Service\Crypto\Schluesselwechsel;

$gruppen = $schluessel !== null ? str_split($schluessel, 4) : null;
$hashes = is_array($stand['hashes'] ?? null) ? $stand['hashes'] : null;
?>
<?php if ($fehler !== null): ?>
    <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
<?php endif; ?>

<?php if ($gruppen !== null && $fertig): ?>
    <p class="hinweis hinweis-ok" role="status">Der Server-Schlüssel ist gewechselt. Nachweise, Lastschriftdateien,
        Authenticator-Apps und der Mailversand funktionieren weiter; mit dem alten Schlüssel lässt sich hier nichts mehr öffnen.</p>
    <figure class="sicherungskarte">
        <figcaption>Sicherungsschlüssel der Mitgliederverwaltung<span class="sicherungskarte-datum">, erstellt am <?= e(date('d.m.Y')) ?></span></figcaption>
        <p class="sicherungskarte-schluessel" aria-label="Sicherungsschlüssel: <?= e(implode(' ', $gruppen)) ?>">
            <?php foreach ($gruppen as $gruppe): ?><span><?= e($gruppe) ?></span> <?php endforeach; ?>
        </p>
        <p class="sicherungskarte-hinweis">Getrennt von den Backups aufbewahren, zum Beispiel ausgedruckt bei einem zweiten Vorstandsmitglied.
            Groß- und Kleinschreibung zählen; die Leerzeichen nicht.</p>
    </figure>
    <form class="formular" hx-post="/admin/schluesselwechsel/ausgedruckt" hx-target="#schluesselwechsel" hx-swap="innerHTML">
        <p class="hinweis hinweis-warnung">Drucken Sie den Schlüssel jetzt aus oder schreiben Sie ihn ab. Nach der Bestätigung
            wird er nicht mehr angezeigt.</p>
        <label class="feld-haken">
            <input type="checkbox" name="notiert" value="1" required>
            Ich habe den neuen Sicherungsschlüssel ausgedruckt oder notiert.
        </label>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Bestätigen</button>
        </p>
    </form>
<?php elseif ($unbestaetigt): ?>
    <form class="formular" hx-post="/admin/schluesselwechsel/anzeigen" hx-target="#schluesselwechsel" hx-swap="innerHTML">
        <p class="hinweis hinweis-warnung">Der neue Sicherungsschlüssel ist noch nicht als ausgedruckt bestätigt. Lassen Sie ihn
            anzeigen und drucken Sie ihn aus – ohne ihn lassen sich neue Backups nicht einspielen.</p>
        <div class="feld">
            <label for="schluesselwechsel-anzeigen-passwort">Ihr Passwort</label>
            <input id="schluesselwechsel-anzeigen-passwort" name="passwort" type="password" autocomplete="current-password" required>
        </div>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Schlüssel anzeigen</button>
        </p>
    </form>
<?php endif; ?>

<?php if ($laeuft): ?>
    <?php if ($stand !== null && $stand['phase'] === Schluesselwechsel::PHASE_WARTEN): ?>
        <p role="status"<?php if ($weiter): ?> hx-post="/admin/schluesselwechsel/schritt" hx-trigger="load delay:5s" hx-target="#schluesselwechsel" hx-swap="innerHTML"<?php endif; ?>>
            <?php if (($stand['wartet_auf_backup'] ?? false) === true): ?>
                Alle Werte sind umgeschlüsselt. Bevor der alte Schlüssel gelöscht wird, wartet der Wechsel, bis das
                laufende Backup fertig ist.
            <?php else: ?>
                Alle Werte sind umgeschlüsselt. Bevor der alte Schlüssel gelöscht wird, wartet der Wechsel noch etwa
                <?= e(max(5, $wartezeit)) ?> Sekunden auf Anfragen, die noch mit dem alten Schlüssel begonnen haben, und prüft dann alles ein zweites Mal.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p role="status"<?php if ($weiter): ?> hx-post="/admin/schluesselwechsel/schritt" hx-trigger="<?= $verzoegert ? 'load delay:3s' : 'load' ?>" hx-target="#schluesselwechsel" hx-swap="innerHTML"<?php endif; ?>>
            <?php if ($stand !== null && $stand['phase'] === Schluesselwechsel::PHASE_UMSCHLUESSELN): ?>
                Schlüssel werden umgeschlüsselt (Durchgang <?= e($stand['durchgang']) ?>): <?= e(min((int) $stand['erledigt'], (int) $stand['gesamt'])) ?> von <?= e($stand['gesamt']) ?> …
            <?php else: ?>
                Der Schlüsselwechsel wird fortgesetzt …
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <p class="gedaempft klein">Der Wechsel läuft nur, solange diese Seite offen ist. Wird er unterbrochen, ist nichts verloren:
        Beim nächsten Öffnen der Einstellungen geht er an derselben Stelle weiter.</p>
    <?php if (!$weiter): ?>
        <p class="knopfreihe">
            <button type="button" class="knopf knopf-haupt" hx-post="/admin/schluesselwechsel/schritt" hx-target="#schluesselwechsel" hx-swap="innerHTML">Fortsetzen</button>
        </p>
    <?php endif; ?>
<?php else: ?>
    <?php if ($fertig): ?>
        <p>Zuletzt gewechselt am <?= e(date('d.m.Y \u\m H:i', strtotime((string) $stand['beendet']) ?: null)) ?> Uhr.</p>
        <ul class="klein">
            <li>Erstellen Sie jetzt ein neues Backup. Ältere Backups lassen sich nur noch mit dem alten Schlüssel einspielen;
                vernichten Sie dessen Ausdruck erst, wenn ein neues Backup vorliegt.</li>
            <?php if ($hashes !== null && $hashes['backup_codes'] > 0): ?>
                <li>Die Backup-Codes aller Benutzer sind ungültig geworden. Jeder Benutzer erzeugt im Benutzermenü unter
                    „Zweiter Faktor“ neue.</li>
            <?php endif; ?>
            <li>Gemerkte Geräte müssen beim nächsten Anmelden den zweiten Faktor wieder eingeben.</li>
            <?php if ((int) ($stand['unlesbar'] ?? 0) > 0): ?>
                <li><?= e($stand['unlesbar']) ?> gespeicherte Werte waren schon vor dem Wechsel mit keinem der beiden Schlüssel lesbar
                    und sind es weiterhin nicht.</li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>

    <form class="formular" hx-post="/admin/schluesselwechsel/start" hx-target="#schluesselwechsel" hx-swap="innerHTML">
        <p>Ist die <code>config.php</code> oder ein Ausdruck des Sicherungsschlüssels in falsche Hände geraten, erzeugen Sie
            hier einen neuen Schlüssel. Die gespeicherten Dateien, die Geheimnisse der Authenticator-Apps und das SMTP-Passwort
            werden dabei auf den neuen Schlüssel umgestellt; das dauert wenige Minuten.</p>
        <ul class="klein">
            <li>Bisherige Backups bleiben mit dem alten Schlüssel verschlüsselt. Erstellen Sie danach ein neues Backup.</li>
            <li>Die Backup-Codes aller Benutzer werden ungültig, gemerkte Geräte werden vergessen.</li>
            <li>Der neue Schlüssel wird am Ende zum Ausdrucken angezeigt.</li>
        </ul>
        <div class="feld">
            <label for="schluesselwechsel-passwort">Ihr Passwort</label>
            <input id="schluesselwechsel-passwort" name="passwort" type="password" autocomplete="current-password" required>
        </div>
        <label class="feld-haken">
            <input type="checkbox" name="verstanden" value="1" required>
            Ich habe die Hinweise gelesen.
        </label>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Server-Schlüssel wechseln</button>
        </p>
    </form>
<?php endif; ?>
