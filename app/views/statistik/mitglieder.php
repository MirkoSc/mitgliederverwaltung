<?php

/**
 * "Statistik – Mitglieder" (docs/spec/09-listen-und-exporte.md section 7):
 * the figures on top, then one section per evaluation. Counts only, no
 * names. Variables: see App\App\StatistikController::mitglieder().
 *
 * @var \App\Service\Statistik\Mitgliederzahlen $zahlen
 * @var array<string, array{string, string}> $reiter
 * @var string $aktiverReiter
 */

$k = $zahlen->kennzahlen;
$zahl = static fn(int $n): string => number_format($n, 0, ',', '.');
$kacheln = [
    ['Mitglieder', $zahl($k->gesamt)],
    ['aktiv', $zahl($k->aktiv)],
    ['passiv', $zahl($k->passiv)],
    ['Durchschnittsalter in Jahren', $k->durchschnittsalter() ?? '–'],
    ['Kinder und Jugendliche unter 18', $zahl($k->minderjaehrig)],
    ['weiblich', $zahl($k->weiblich)],
    ['männlich', $zahl($k->maennlich)],
    ['divers', $zahl($k->divers)],
    ['Geschlecht ohne Angabe', $zahl($k->ohneGeschlecht)],
];
?>
<?php require __DIR__ . '/reiter.php'; ?>

<p class="statistik-stand">Stand heute, <?= e($zahlen->stichtag->format('d.m.Y')) ?>. Gezählt sind die aktuellen
    Mitglieder – ehemalige nur bei den Austritten.</p>

<dl class="kennzahlen">
    <?php foreach ($kacheln as [$titel, $wert]): ?>
        <div class="kennzahl">
            <dt><?= e($titel) ?></dt>
            <dd><?= e($wert) ?></dd>
        </div>
    <?php endforeach; ?>
</dl>

<nav class="sprungleiste" aria-label="Auswertungen">
    <ul>
        <?php foreach ($zahlen->auswertungen as $auswertung): ?>
            <li><a href="#<?= e($auswertung->id) ?>"><?= e($auswertung->titel) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>

<?php foreach ($zahlen->auswertungen as $auswertung): ?>
    <?php require __DIR__ . '/auswertung.php'; ?>
<?php endforeach; ?>
