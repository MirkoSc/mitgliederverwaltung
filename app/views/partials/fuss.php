<?php

/**
 * Footer: Impressum and Datenschutz on every page (issue #14 brings the
 * pages; the links appear as soon as their routes exist), and the version.
 *
 * @var string $version
 * @var \Closure(\App\Http\HttpMethod, string): bool $routeVorhanden
 */

use App\Http\HttpMethod;

$rechtstexte = array_filter(
    ['/impressum' => 'Impressum', '/datenschutz' => 'Datenschutz'],
    static fn(string $label, string $href): bool => $routeVorhanden(HttpMethod::Get, $href),
    ARRAY_FILTER_USE_BOTH,
);
?>
<footer class="fuss">
    <?php if ($rechtstexte !== []): ?>
        <ul class="fuss-links">
            <?php foreach ($rechtstexte as $href => $label): ?>
                <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <span class="fuss-version">Version <?= e($version) ?></span>
</footer>
