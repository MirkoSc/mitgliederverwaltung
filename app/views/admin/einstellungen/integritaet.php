<?php

/**
 * Fragment: state of the integrity check. While a chain runs, the fragment
 * asks for the next step itself (hx-trigger="load"), one short request each.
 *
 * @var array<string, mixed>|null $stand
 * @var bool $laeuft
 * @var ?string $hindernis
 * @var ?string $warnung
 * @var ?string $signaturHinweis
 * @var array<string, string> $arten
 */

use App\Service\Integritaet\CodeIntegritaet;

$fertig = $stand !== null && $stand['phase'] === CodeIntegritaet::PHASE_FERTIG;
?>
<?php if ($hindernis !== null): ?>
    <p class="hinweis hinweis-warnung"><?= e($hindernis) ?></p>
<?php endif; ?>
<?php if ($warnung !== null): ?>
    <p class="hinweis hinweis-warnung"><?= e($warnung) ?></p>
<?php endif; ?>
<?php if ($signaturHinweis !== null): ?>
    <p class="hinweis hinweis-info"><?= e($signaturHinweis) ?></p>
<?php endif; ?>

<?php if ($laeuft && $stand !== null): ?>
    <p role="status" hx-post="/admin/integritaet/schritt" hx-trigger="load" hx-target="#integritaet" hx-swap="innerHTML">
        Prüfung läuft: <?= e(min((int) $stand['position'], (int) $stand['gesamt'])) ?> von <?= e($stand['gesamt']) ?> Dateien …
    </p>
<?php elseif ($fertig && $stand !== null): ?>
    <?php if (CodeIntegritaet::unveraendert($stand)): ?>
        <p class="hinweis hinweis-ok">Alle <?= e($stand['gesamt']) ?> Dateien stimmen mit dem Release überein
            (geprüft am <?= e($stand['beendet']) ?>).</p>
    <?php else: ?>
        <p class="hinweis hinweis-fehler"><strong>Abweichungen gefunden</strong> (geprüft am <?= e($stand['beendet']) ?>).
            Wenn Sie nichts selbst geändert haben: Zugangsdaten zum Webspace ändern und das Release neu einspielen.</p>
        <?php foreach ($arten as $art => $titel): ?>
            <?php if ((int) $stand['anzahl'][$art] > 0): ?>
                <h4><?= e($titel) ?> (<?= e($stand['anzahl'][$art]) ?>)</h4>
                <ul>
                    <?php foreach ($stand['pfade'][$art] as $pfad): ?>
                        <li><code><?= e($pfad) ?></code></li>
                    <?php endforeach; ?>
                    <?php if ((int) $stand['anzahl'][$art] > count($stand['pfade'][$art])): ?>
                        <li class="gedaempft">… und weitere</li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$laeuft): ?>
    <p class="knopfreihe">
        <button type="button" class="knopf knopf-haupt" hx-post="/admin/integritaet/start" hx-target="#integritaet" hx-swap="innerHTML">
            <?= $fertig ? 'Erneut prüfen' : 'Prüfung starten' ?>
        </button>
    </p>
<?php endif; ?>
