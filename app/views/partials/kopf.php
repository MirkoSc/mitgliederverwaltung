<?php

/**
 * The header: name of the application, the main navigation and the user
 * menu (docs/spec/06-oberflaeche.md section 2). Both lists come from
 * App\View\Navigation and show only what the account may open; an entry
 * whose route is not built yet renders as inactive text.
 *
 * @var \App\View\Area $bereich
 * @var string $pfad
 * @var string $appName
 * @var string $logoUrl club logo or the neutral placeholder (App\View\View)
 * @var string $version
 * @var \App\Domain\Konto|null $konto
 * @var string|null $csrfToken
 * @var \Closure(\App\Http\HttpMethod, string): bool $routeVorhanden
 */

use App\Http\HttpMethod;
use App\View\Navigation;

$zeigeNavigation = $bereich->mitNavigation() && ($konto ?? null) !== null;
$hauptpunkte = $zeigeNavigation
    ? array_values(array_filter(Navigation::haupt(), static fn($eintrag): bool => $eintrag->sichtbarFuer($konto)))
    : [];
$menue = $zeigeNavigation ? Navigation::benutzermenue($konto) : [];
?>
<header class="kopf">
    <div class="kopf-zeile">
        <?php /* alt="" - the name right next to it says the same. */ ?>
        <a class="marke" href="<?= $zeigeNavigation ? '/app' : '/' ?>">
            <img class="marke-logo" src="<?= e($logoUrl) ?>" alt="" width="40" height="40">
            <span class="marke-name"><?= e($appName) ?></span>
        </a>

        <?php if ($zeigeNavigation): ?>
            <details class="benutzermenue">
                <summary>
                    <span class="benutzermenue-name"><?= e($konto->name) ?></span>
                    <span class="benutzermenue-rolle"><?= e($konto->rolle->bezeichnung()) ?></span>
                </summary>
                <ul class="benutzermenue-liste">
                    <?php foreach ($menue as $eintrag): ?>
                        <li>
                            <?php if (!$routeVorhanden($eintrag->methode, $eintrag->href)): ?>
                                <span class="eintrag-spaeter" aria-disabled="true"><?= e($eintrag->label) ?> <small>(folgt)</small></span>
                            <?php elseif ($eintrag->methode === HttpMethod::Post): ?>
                                <form method="post" action="<?= e($eintrag->href) ?>">
                                    <input type="hidden" name="_csrf" value="<?= e((string) $csrfToken) ?>">
                                    <button type="submit" class="menue-knopf"><?= e($eintrag->label) ?></button>
                                </form>
                            <?php else: ?>
                                <a href="<?= e($eintrag->href) ?>"<?= $eintrag->istAktiv($pfad) ? ' aria-current="page"' : '' ?>><?= e($eintrag->label) ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </div>

    <?php if ($hauptpunkte !== []): ?>
        <nav class="navi" aria-label="Hauptnavigation">
            <ul>
                <?php foreach ($hauptpunkte as $eintrag): ?>
                    <li>
                        <?php if ($routeVorhanden($eintrag->methode, $eintrag->href)): ?>
                            <a href="<?= e($eintrag->href) ?>"<?= $eintrag->istAktiv($pfad) ? ' aria-current="page"' : '' ?>><?= e($eintrag->label) ?></a>
                        <?php else: ?>
                            <span class="eintrag-spaeter" aria-disabled="true"><?= e($eintrag->label) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>
</header>
