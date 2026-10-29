<?php

/**
 * Fragment: the result lines of the system check.
 *
 * @var list<\App\Service\SystemCheck\CheckResult> $ergebnisse
 */

use App\Service\SystemCheck\CheckStatus;
use App\Service\SystemCheck\SystemCheck;

$schlimmster = SystemCheck::worstOf($ergebnisse);
$etikett = static fn(CheckStatus $s): array => match ($s) {
    CheckStatus::Ok => ['etikett-ok', 'In Ordnung'],
    CheckStatus::Warn => ['etikett-warnung', 'Warnung'],
    CheckStatus::Fail => ['etikett-fehler', 'Fehler'],
};
?>
<p class="hinweis <?= e(match ($schlimmster) {
    CheckStatus::Ok => 'hinweis-ok',
    CheckStatus::Warn => 'hinweis-warnung',
    CheckStatus::Fail => 'hinweis-fehler',
}) ?>">
    <?= e(match ($schlimmster) {
        CheckStatus::Ok => 'Alle Prüfungen sind in Ordnung.',
        CheckStatus::Warn => 'Es gibt Warnungen.',
        CheckStatus::Fail => 'Mindestens eine Prüfung ist fehlgeschlagen.',
    }) ?>
</p>
<div class="tabelle-rahmen">
    <table class="liste">
        <caption>Ergebnis des Systemchecks</caption>
        <thead>
            <tr><th scope="col">Prüfung</th><th scope="col">Erwartet</th><th scope="col">Ist</th><th scope="col">Bewertung</th></tr>
        </thead>
        <tbody>
        <?php foreach ($ergebnisse as $r): [$klasse, $text] = $etikett($r->status); ?>
            <tr>
                <th scope="row"><?= e($r->label) ?><?php if ($r->status !== CheckStatus::Ok && $r->detail !== ''): ?>
                    <br><span class="gedaempft klein"><?= e($r->detail) ?></span><?php endif; ?></th>
                <td data-label="Erwartet"><?= e($r->expected) ?></td>
                <td data-label="Ist"><?= e($r->actual) ?></td>
                <td data-label="Bewertung"><span class="etikett <?= e($klasse) ?>"><?= e($text) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="knopfreihe"><button type="button" class="knopf" hx-get="/admin/systemcheck" hx-target="#systemcheck" hx-swap="innerHTML">Erneut prüfen</button></p>
