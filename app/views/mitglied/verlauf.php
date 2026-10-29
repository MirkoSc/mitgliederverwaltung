<?php

/**
 * The tab "Verlauf" of an opened member (App\App\Mitglied\VerlaufReiter,
 * docs/spec/07-mitglieder.md section 7): versions newest first, the changed
 * fields as "alt -> neu", "Zurückholen" per older version.
 *
 * @var int $mitgliedId
 * @var list<array{version: \App\Domain\MitgliedVersion, aenderungen: list<array{feld: string, alt: string, neu: string}>, wer: ?string, aktuell: bool}> $eintraege
 * @var bool $darfZurueckholen
 * @var string|null $csrf
 */

use App\App\VerlaufController;
use App\Support\Datum;
?>
<?php if ($eintraege === []): ?>
    <p class="leer">Zu diesem Mitglied gibt es noch keine Versionen.</p>
<?php else: ?>
    <ol class="verlauf">
        <?php foreach ($eintraege as $eintrag): ?>
            <?php $version = $eintrag['version']; ?>
            <li class="verlauf-eintrag">
                <h3>
                    Version <?= e($version->nr) ?>
                    <span class="verlauf-anlass"><?= e($version->anlass->bezeichnung()) ?></span>
                    <?php if ($eintrag['aktuell']): ?><span class="etikett etikett-ok">aktueller Stand</span><?php endif; ?>
                </h3>
                <p class="verlauf-wann">
                    <?= e(Datum::zeitpunktAnzeigen($version->geaendertAm)) ?>
                    <?php if ($eintrag['wer'] !== null): ?>, <?= e($eintrag['wer']) ?><?php endif; ?>
                </p>
                <?php if ($eintrag['aenderungen'] !== []): ?>
                    <ul class="verlauf-aenderungen">
                        <?php foreach ($eintrag['aenderungen'] as $aenderung): ?>
                            <li><strong><?= e($aenderung['feld']) ?>:</strong>
                                <span class="verlauf-alt"><?= e($aenderung['alt']) ?></span>
                                <span aria-label="wurde zu">→</span>
                                <span class="verlauf-neu"><?= e($aenderung['neu']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($darfZurueckholen && !$eintrag['aktuell']): ?>
                    <form method="post" action="<?= e(VerlaufController::adresse($mitgliedId, $version->nr)) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                        <button type="submit" class="knopf knopf-still">Diese Version zurückholen</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
