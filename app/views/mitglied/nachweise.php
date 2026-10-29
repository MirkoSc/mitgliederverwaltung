<?php

/**
 * The section "Nachweise" of an opened member (App\App\Mitglied\
 * NachweiseAbschnitt, docs/spec/07-mitglieder.md section 5).
 *
 * @var int $mitgliedId
 * @var list<\App\Domain\Nachweis> $nachweise
 * @var array<int, string|null> $namen user id => name, null when the account is gone
 * @var list<\App\Domain\NachweisArt> $arten
 * @var \DateTimeImmutable $heute
 * @var bool $darfPflegen
 * @var bool $darfLoeschen
 * @var string|null $csrf
 */

use App\App\NachweisController;
?>
<section class="nachweise" aria-labelledby="nachweise-titel">
    <h2 id="nachweise-titel">Nachweise</h2>
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
                                <button type="submit" class="knopf knopf-gefahr">Nachweis löschen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($darfPflegen): ?>
        <form method="post" action="<?= e(NachweisController::adresse($mitgliedId)) ?>" enctype="multipart/form-data" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
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
