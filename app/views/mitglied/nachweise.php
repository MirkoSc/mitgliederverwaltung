<?php

/**
 * The tab "Nachweise" of an opened member (App\App\Mitglied\
 * NachweiseReiter, docs/spec/07-mitglieder.md section 5).
 *
 * @var int $mitgliedId
 * @var list<\App\Domain\Nachweis> $nachweise
 * @var array<int, string|null> $namen user id => name, null when the account is gone
 * @var list<\App\Domain\NachweisArt> $arten
 * @var \DateTimeImmutable $heute
 * @var bool $darfPflegen
 * @var bool $darfLoeschen
 * @var string|null $csrf
 * @var \App\App\Mitglied\Einbettung|null $einbettung the page the member is shown on (MitgliedAnsicht)
 */

use App\App\Mitglied\Einbettung;
use App\App\NachweisController;

$einbettung ??= Einbettung::mitgliederSeite();
?>
<section class="nachweise" aria-labelledby="nachweise-titel">
    <h2 id="nachweise-titel" class="nur-lesehilfe">Nachweise</h2>
    <?php if ($nachweise === []): ?>
        <p>Keine Nachweise vermerkt.</p>
    <?php else: ?>
        <ul class="nachweise-liste">
            <?php foreach ($nachweise as $nachweis): ?>
                <li>
                    <p>
                        <strong><?= e($nachweis->art->bezeichnung()) ?></strong>,
                        <?php if ($nachweis->gueltigBis === null): ?>
                            ohne Ablaufdatum
                        <?php else: ?>
                            gültig bis <?= e(datum($nachweis->gueltigBis)) ?>
                            <?php if ($nachweis->istAbgelaufenAm($heute)): ?>
                                <span class="etikett etikett-warnung">abgelaufen</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($nachweis->bemerkung !== ''): ?>
                        <p><?= e($nachweis->bemerkung) ?></p>
                    <?php endif; ?>
                    <p class="feld-hinweis">
                        Vermerkt von <?= e(($nachweis->vermerktVon !== null ? ($namen[$nachweis->vermerktVon] ?? null) : null) ?? 'einem gelöschten Zugang') ?>
                        am <?= e(zeitpunkt($nachweis->vermerktAm)) ?>
                    </p>
                    <div class="knopfreihe">
                        <?php if ($nachweis->blobId !== null): ?>
                            <a class="knopf" href="<?= e(NachweisController::adresse($mitgliedId, $nachweis->id . '/datei')) ?>">Datei herunterladen</a>
                        <?php endif; ?>
                        <?php if ($darfLoeschen): ?>
                            <form method="post" action="<?= e(NachweisController::adresse($mitgliedId, $nachweis->id . '/loeschen')) ?>">
                                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                                <?= $einbettung->feld() ?>
                                <button type="submit" class="knopf knopf-gefahr">Nachweis löschen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($darfPflegen): ?>
                        <?php $feld = 'nachweis-' . $nachweis->id; ?>
                        <details class="nachweis-bearbeiten">
                            <summary>Nachweis bearbeiten</summary>
                            <form method="post" action="<?= e(NachweisController::adresse($mitgliedId, $nachweis->id . '/bearbeiten')) ?>" class="formular">
                                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                                <?= $einbettung->feld() ?>
                                <div class="feld">
                                    <label for="<?= e($feld) ?>-art">Art</label>
                                    <select id="<?= e($feld) ?>-art" name="art" required>
                                        <?php foreach ($arten as $art): ?>
                                            <option value="<?= e($art->value) ?>"<?= $art === $nachweis->art ? ' selected' : '' ?>><?= e($art->bezeichnung()) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="feld">
                                    <label for="<?= e($feld) ?>-bis">Gültig bis (optional)</label>
                                    <input type="date" id="<?= e($feld) ?>-bis" name="gueltig_bis" value="<?= e($nachweis->gueltigBis?->format('Y-m-d') ?? '') ?>" aria-describedby="<?= e($feld) ?>-bis-hinweis">
                                    <p id="<?= e($feld) ?>-bis-hinweis" class="feld-hinweis">Leer heißt: ohne Ablaufdatum.</p>
                                </div>
                                <div class="feld">
                                    <label for="<?= e($feld) ?>-bemerkung">Bemerkung (optional)</label>
                                    <input type="text" id="<?= e($feld) ?>-bemerkung" name="bemerkung" maxlength="500" value="<?= e($nachweis->bemerkung) ?>">
                                </div>
                                <p class="feld-hinweis">Die Datei und „vermerkt von/am“ bleiben, wie sie sind.</p>
                                <div class="knopfreihe">
                                    <button type="submit" class="knopf knopf-haupt">Änderungen speichern</button>
                                </div>
                            </form>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($darfPflegen): ?>
        <form method="post" action="<?= e(NachweisController::adresse($mitgliedId)) ?>" enctype="multipart/form-data" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <?= $einbettung->feld() ?>
            <div class="feld">
                <label for="nachweis-art">Art</label>
                <select id="nachweis-art" name="art" required>
                    <?php foreach ($arten as $art): ?>
                        <option value="<?= e($art->value) ?>"><?= e($art->bezeichnung()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="feld">
                <label for="nachweis-bis">Gültig bis (optional)</label>
                <input type="date" id="nachweis-bis" name="gueltig_bis">
            </div>
            <div class="feld">
                <label for="nachweis-bemerkung">Bemerkung (optional)</label>
                <input type="text" id="nachweis-bemerkung" name="bemerkung" maxlength="500">
            </div>
            <div class="feld">
                <label for="nachweis-datei">Datei (optional)</label>
                <input type="file" id="nachweis-datei" name="datei" accept="application/pdf,image/jpeg,image/png,image/webp" aria-describedby="nachweis-datei-hinweis">
                <p id="nachweis-datei-hinweis" class="feld-hinweis">PDF oder Bild (JPEG, PNG, WebP), höchstens 10 MB. Die Datei wird verschlüsselt gespeichert.</p>
            </div>
            <button type="submit" class="knopf knopf-haupt">Nachweis vermerken</button>
        </form>
    <?php endif; ?>
</section>
