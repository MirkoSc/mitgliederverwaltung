<?php

/**
 * Settings section Benutzer (docs/spec/02-sicherheit.md section 5): one
 * entry per account - opened, it shows what can be done with it - and the
 * invitation form below. No script: the entries are <details>, deleting
 * asks for a tick first. The Barrierefrei-Modus can be set for an account
 * at the invitation and switched later (issue #87).
 *
 * @var list<array{benutzer: \App\Domain\Benutzer, selbst: bool, einladungBis: \DateTimeImmutable|null}> $zeilen
 * @var list<\App\Domain\Rolle> $rollen
 * @var int $gueltigStunden
 * @var string $csrf
 */

use App\Domain\BenutzerStatus;

$etikett = static fn(BenutzerStatus $status): string => match ($status) {
    BenutzerStatus::Aktiv => 'etikett-ok',
    BenutzerStatus::Eingeladen => 'etikett-info',
    BenutzerStatus::Gesperrt => 'etikett-fehler',
};
?>
<p>Admins dürfen alles. Der Vorstand sucht, sieht und bearbeitet Mitglieder und nutzt die Listen –
    Beiträge, Einzug, Einstellungen und Benutzer bleiben dem Admin vorbehalten.
    „Nur lesen“ sieht Mitglieder, Familien, Nachweise und Listen samt Exporten und, ob ein Mitglied bezahlt hat –
    ändern kann diese Rolle nichts.</p>

<ul class="benutzer-liste">
    <?php foreach ($zeilen as $zeile): $b = $zeile['benutzer']; $id = 'benutzer-' . $b->id; ?>
        <li>
            <details class="benutzer-eintrag">
                <summary>
                    <span class="benutzer-name"><?= e($b->name) ?><?= $zeile['selbst'] ? ' <span class="gedaempft">(Sie)</span>' : '' ?></span>
                    <span class="benutzer-rolle"><?= e($b->rolle->bezeichnung()) ?></span>
                    <span class="etikett <?= e($etikett($b->status)) ?>"><?= e($b->status->bezeichnung()) ?></span>
                </summary>
                <div class="benutzer-inhalt">
                    <dl class="angaben">
                        <dt>E-Mail-Adresse</dt>
                        <dd><?= e($b->email) ?></dd>
                        <?php if ($b->status === BenutzerStatus::Eingeladen): ?>
                            <dt>Einladung</dt>
                            <dd><?= $zeile['einladungBis'] !== null
                                ? 'gültig bis ' . e($zeile['einladungBis']->format('d.m.Y, H:i')) . ' Uhr'
                                : 'abgelaufen – bitte erneut senden' ?></dd>
                        <?php else: ?>
                            <dt>Letzte Anmeldung</dt>
                            <dd><?= $b->lastLoginAt !== null ? e($b->lastLoginAt->format('d.m.Y, H:i')) . ' Uhr' : 'noch nie' ?></dd>
                        <?php endif; ?>
                        <dt>Barrierefrei-Modus</dt>
                        <dd><?= $b->barrierefrei ? 'an' : 'aus' ?></dd>
                    </dl>

                    <?php if ($zeile['selbst']): ?>
                        <p class="gedaempft">Ihr eigenes Konto. Rolle, Sperre und Löschen ändert ein anderer Admin.</p>
                    <?php else: ?>
                        <form method="post" action="/admin/benutzer/<?= e($b->id) ?>/rolle" class="benutzer-rolle-form">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <div class="feld">
                                <label for="<?= e($id) ?>-rolle">Rolle</label>
                                <select id="<?= e($id) ?>-rolle" name="rolle">
                                    <?php foreach ($rollen as $rolle): ?>
                                        <option value="<?= e($rolle->value) ?>"<?= $rolle === $b->rolle ? ' selected' : '' ?>><?= e($rolle->bezeichnung()) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="knopf">Rolle speichern</button>
                        </form>

                        <div class="knopfreihe">
                            <form method="post" action="/admin/benutzer/<?= e($b->id) ?>/barrierefrei">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="an" value="<?= $b->barrierefrei ? '0' : '1' ?>">
                                <button type="submit" class="knopf"><?= $b->barrierefrei ? 'Barrierefrei-Modus ausschalten' : 'Barrierefrei-Modus einschalten' ?></button>
                            </form>
                            <?php if ($b->status === BenutzerStatus::Eingeladen): ?>
                                <form method="post" action="/admin/benutzer/<?= e($b->id) ?>/einladung">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button type="submit" class="knopf">Einladung erneut senden</button>
                                </form>
                            <?php elseif ($b->status === BenutzerStatus::Aktiv): ?>
                                <form method="post" action="/admin/benutzer/<?= e($b->id) ?>/sperren">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button type="submit" class="knopf">Konto sperren</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/admin/benutzer/<?= e($b->id) ?>/entsperren">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button type="submit" class="knopf">Sperre aufheben</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <form method="post" action="/admin/benutzer/<?= e($b->id) ?>/loeschen" class="benutzer-loeschen">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <label class="feld-haken"><input type="checkbox" name="bestaetigt" value="1" required> Ja, dieses Konto endgültig löschen</label>
                            <button type="submit" class="knopf knopf-gefahr">Benutzer löschen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        </li>
    <?php endforeach; ?>
</ul>

<form method="post" action="/admin/benutzer" class="formular benutzer-einladen">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <h3>Benutzer einladen</h3>
    <p class="gedaempft">Die eingeladene Person bekommt einen Link per Mail (<?= e($gueltigStunden) ?> Stunden gültig) und legt damit ihr Passwort selbst fest.</p>
    <div class="feld">
        <label for="einladen-name">Name</label>
        <input id="einladen-name" name="name" type="text" maxlength="100" autocomplete="off" required>
    </div>
    <div class="feld">
        <label for="einladen-email">E-Mail-Adresse</label>
        <input id="einladen-email" name="email" type="email" autocomplete="off" required>
    </div>
    <div class="feld">
        <label for="einladen-rolle">Rolle</label>
        <select id="einladen-rolle" name="rolle">
            <?php foreach ($rollen as $rolle): ?>
                <option value="<?= e($rolle->value) ?>"<?= $rolle === \App\Domain\Rolle::Vorstand ? ' selected' : '' ?>><?= e($rolle->bezeichnung()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="feld">
        <label class="feld-haken"><input type="checkbox" name="barrierefrei" value="1" aria-describedby="einladen-barrierefrei-hilfe"> Barrierefrei-Modus von Anfang an einschalten</label>
        <p class="feld-hinweis" id="einladen-barrierefrei-hilfe">Große Schrift, hoher Kontrast, große Knöpfe. Die Person kann ihn im Benutzermenü selbst wieder ausschalten.</p>
    </div>
    <button type="submit" class="knopf knopf-haupt">Einladung senden</button>
</form>
