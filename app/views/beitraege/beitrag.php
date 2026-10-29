<?php

/**
 * The page of one annual fee (docs/spec/08-beitraege.md section 2): what it
 * is, what can happen to it in its status, and its notes. Opened from the
 * fees page and from the member tab "Beiträge"; every form returns here.
 *
 * @var \App\Domain\Jahresbeitrag $beitrag
 * @var \App\Domain\Mitglied $mitglied
 * @var array<int, string|null> $namen
 * @var string $zurueck
 * @var string $zurueckText
 * @var string $hier
 * @var list<\App\Domain\Zahlweise> $zahlweisen
 * @var \DateTimeImmutable $heute
 * @var string|null $csrf
 */

use App\App\BeitraegeController;
use App\App\EinzugController;
use App\App\MitgliedController;
use App\Domain\JahresbeitragStatus;
use App\Domain\Zahlweise;
use App\Support\Geld;

$statusEtikett = require __DIR__ . '/status-etikett.php';
$aktion = BeitraegeController::adresse((int) $beitrag->id);
$versteckt = '<input type="hidden" name="_csrf" value="' . e($csrf ?? '') . '"><input type="hidden" name="zurueck" value="' . e($hier) . '">';
?>
<a class="zurueck" href="<?= e($zurueck) ?>"><?= e($zurueckText) ?></a>

<div class="seitenkopf">
    <h1>Jahresbeitrag <?= e($beitrag->jahr) ?></h1>
    <p><a href="<?= e(MitgliedController::adresse((int) $mitglied->id) . '&reiter=beitraege') ?>"><?= e($mitglied->anzeigeName()) ?></a>,
        <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span></p>
</div>

<dl class="angaben">
    <dt>Betrag</dt>
    <dd><?= e(geld($beitrag->betragCent)) ?></dd>
    <dt>Status</dt>
    <dd><?= $statusEtikett($beitrag->status) ?></dd>
    <?php if ($beitrag->status === JahresbeitragStatus::Bezahlt): ?>
        <dt>Bezahlt</dt>
        <dd><?= e(datum($beitrag->bezahltAm)) ?>,
            <?= $beitrag->zahlweise !== null ? e($beitrag->zahlweise->bezeichnung()) : 'per Lastschrift' ?></dd>
    <?php endif; ?>
    <?php if ($beitrag->einzugId !== null): ?>
        <dt>Einzug</dt>
        <dd><a href="<?= e(EinzugController::adresse($beitrag->einzugId)) ?>">Nr. <?= e($beitrag->einzugId) ?></a></dd>
    <?php endif; ?>
</dl>

<?php if ($beitrag->status === JahresbeitragStatus::ImEinzug): ?>
    <p class="hinweis hinweis-info">Dieser Beitrag steckt im
        <a href="<?= e(EinzugController::adresse((int) $beitrag->einzugId)) ?>">Einzug Nr. <?= e($beitrag->einzugId) ?></a>. Sobald die Bank
        eingezogen hat, bestätigen Sie den Einzug – dann ist er bezahlt. Wurde die Datei nicht
        eingereicht, verwerfen Sie den Einzug; dann ist der Beitrag wieder offen.</p>
<?php endif; ?>

<div class="beitrag-aktionen">
    <?php if ($beitrag->status === JahresbeitragStatus::Offen): ?>
        <section class="abschnitt" aria-labelledby="bezahlt-titel">
            <h2 id="bezahlt-titel">Als bezahlt vermerken</h2>
            <form class="formular" method="post" action="<?= e($aktion) ?>/bezahlt">
                <?= $versteckt ?>
                <fieldset class="feldgruppe">
                    <legend>Wie wurde bezahlt?</legend>
                    <?php foreach ($zahlweisen as $zahlweise): ?>
                        <label class="feld-haken"><input type="radio" name="zahlweise" value="<?= e($zahlweise->value) ?>"<?= $zahlweise === Zahlweise::Bar ? ' checked' : '' ?>> <?= e($zahlweise->bezeichnung()) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="feld feld-kurz">
                    <label for="bezahlt-am">Bezahlt am</label>
                    <input type="date" id="bezahlt-am" name="bezahlt_am" value="<?= e($heute->format('Y-m-d')) ?>" required>
                </div>
                <div class="feld">
                    <label for="bezahlt-vermerk">Vermerk (optional)</label>
                    <input type="text" id="bezahlt-vermerk" name="vermerk" maxlength="500">
                </div>
                <button type="submit" class="knopf knopf-haupt">Als bezahlt vermerken</button>
            </form>
        </section>

        <section class="abschnitt" aria-labelledby="betrag-titel">
            <h2 id="betrag-titel">Betrag ändern</h2>
            <form class="formular" method="post" action="<?= e($aktion) ?>/betrag">
                <?= $versteckt ?>
                <div class="feld feld-kurz">
                    <label for="betrag">Neuer Betrag in Euro</label>
                    <input type="text" id="betrag" name="betrag" value="<?= e(Geld::eingabeWert($beitrag->betragCent)) ?>" inputmode="decimal" required aria-describedby="betrag-hilfe">
                    <p class="feld-hinweis" id="betrag-hilfe">Nur solange der Beitrag offen ist. Die Änderung steht mit Ihrem Namen in den Vermerken.</p>
                </div>
                <div class="feld">
                    <label for="betrag-grund">Grund (optional)</label>
                    <input type="text" id="betrag-grund" name="grund" maxlength="500">
                </div>
                <button type="submit" class="knopf">Betrag ändern</button>
            </form>
        </section>
    <?php elseif ($beitrag->status === JahresbeitragStatus::Bezahlt): ?>
        <section class="abschnitt" aria-labelledby="zurueck-titel">
            <h2 id="zurueck-titel">Rücklastschrift</h2>
            <form class="formular" method="post" action="<?= e($aktion) ?>/ruecklastschrift">
                <?= $versteckt ?>
                <p>Kam die Lastschrift zurück oder wurde die Zahlung irrtümlich vermerkt, setzen Sie den Beitrag wieder auf
                    offen. Danach können Sie ihn erneut einziehen oder als bezahlt vermerken.</p>
                <div class="feld">
                    <label for="zurueck-vermerk">Vermerk</label>
                    <input type="text" id="zurueck-vermerk" name="vermerk" maxlength="500" required aria-describedby="zurueck-hilfe">
                    <p class="feld-hinweis" id="zurueck-hilfe">Pflicht – zum Beispiel „Konto erloschen, Bank meldet Rücklastschrift am 12.03.“</p>
                </div>
                <button type="submit" class="knopf knopf-gefahr">Zurück auf offen setzen</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="abschnitt" aria-labelledby="vermerke-titel">
        <h2 id="vermerke-titel">Vermerke</h2>
        <?php $vermerke = $beitrag->vermerke; require __DIR__ . '/vermerke.php'; ?>
        <form class="formular" method="post" action="<?= e($aktion) ?>/vermerk">
            <?= $versteckt ?>
            <div class="feld">
                <label for="neuer-vermerk">Neuer Vermerk</label>
                <input type="text" id="neuer-vermerk" name="vermerk" maxlength="500" required>
            </div>
            <button type="submit" class="knopf">Vermerk speichern</button>
        </form>
    </section>
</div>
