<?php

/**
 * The opened member beside the list (design system "Liste neben
 * Mitglied"). Hints and sections on top, then the tabs - all of them
 * extensions found by file name (App\App\Mitglied\Reiter, ...Abschnitt),
 * which issue #19 and the issues after it fill.
 *
 * As long as no tab exists, a short read-only summary stands in its place.
 *
 * @var \App\Domain\Mitglied|null $mitglied
 * @var \Closure $url
 * @var list<\App\Domain\Abteilung> $abteilungen
 * @var list<\App\Domain\Beitragsart> $beitragsarten
 * @var list<\App\App\Mitglied\Reiter>|null $reiter
 * @var string|null $gewaehlterReiter
 * @var string|null $reiterInhalt
 * @var list<string>|null $abschnitte
 * @var string|null $statusText
 * @var string|null $statusKlasse
 */

$bereichLink = static fn(string $ziel): string => 'href="' . e($ziel) . '" hx-post="' . e($ziel)
    . '" hx-include="#mitglieder-form" hx-target="#mitglieder-bereich" hx-swap="outerHTML show:window:top"';
?>
<div class="geteilt-detail">
    <?php if ($mitglied === null): ?>
        <div class="leer">
            <p>Wählen Sie links ein Mitglied aus. Es öffnet sich hier, die Liste bleibt stehen.</p>
        </div>
    <?php else: ?>
        <a class="zurueck" <?= $bereichLink($url(['mitglied' => null, 'reiter' => null])) ?>>Zurück zur Liste</a>
        <div class="detail-kopf">
            <h2><?= e($mitglied->anzeigeName()) ?></h2>
            <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span>
            <span class="etikett <?= e($statusKlasse) ?>"><?= e($statusText) ?></span>
        </div>

        <?php foreach ($abschnitte ?? [] as $abschnitt): ?>
            <?= $abschnitt ?>
        <?php endforeach; ?>

        <?php if ($reiter !== []): ?>
            <nav class="reiter" aria-label="Bereiche des Mitglieds">
                <ul>
                    <?php foreach ($reiter as $eintrag): ?>
                        <li><a <?= $bereichLink($url(['reiter' => $eintrag->schluessel()])) ?><?= $eintrag->schluessel() === $gewaehlterReiter ? ' aria-current="page"' : '' ?>><?= e($eintrag->titel()) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
            <?= $reiterInhalt ?>
        <?php else: ?>
            <?php
            $namen = static function (array $liste, array $ids): string {
                $treffer = [];
                foreach ($liste as $eintrag) {
                    if (in_array($eintrag->id, $ids, true)) {
                        $treffer[] = $eintrag->name;
                    }
                }

                return implode(', ', $treffer);
            };
            $anschrift = trim($mitglied->strasse . ', ' . trim($mitglied->plz . ' ' . $mitglied->ort), ', ');
            $kontakt = implode(', ', array_filter([$mitglied->telefon, $mitglied->handy, $mitglied->email], static fn(string $w): bool => $w !== ''));
            $angaben = [
                'Anschrift' => $anschrift,
                'Geburtsdatum' => datum($mitglied->geburtsdatum),
                'Kontakt' => $kontakt,
                'Abteilung' => $namen($abteilungen, $mitglied->abteilungIds),
                'Beitragsart' => $namen($beitragsarten, $mitglied->beitragsartId === null ? [] : [$mitglied->beitragsartId]),
                'Eintritt' => datum($mitglied->eintrittsdatum),
                'Austritt' => datum($mitglied->austrittsdatum),
            ];
            ?>
            <dl class="angaben">
                <?php foreach ($angaben as $name => $wert): ?>
                    <?php if ($wert !== ''): ?>
                        <dt><?= e($name) ?></dt>
                        <dd><?= e($wert) ?></dd>
                    <?php endif; ?>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    <?php endif; ?>
</div>
