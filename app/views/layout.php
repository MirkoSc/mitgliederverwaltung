<?php

/**
 * The one shell around every page (docs/spec/06-oberflaeche.md section 1).
 * What differs per area comes from $bereich (App\View\Area): navigation, and
 * whether a CSRF token travels with htmx requests.
 *
 * @var \App\View\Area $bereich
 * @var string $content
 * @var string $partialsDir
 * @var string $appName
 * @var string $version
 * @var list<string> $stylesheets
 * @var \App\Domain\Konto|null $konto
 */
$seitentitel = ($title ?? '') !== '' ? $title . ' – ' . $appName : $appName;
// The public pages may have no session, so they have no token - and then
// the body carries no hx-headers at all.
$csrfToken = ($bereich !== \App\View\Area::Oeffentlich && ($csrf ?? '') !== '') ? (string) $csrf : null;
// Barrierefrei-Modus: one class on <html> that overrides the tokens of the
// design system (06 section 4) - no second layout.
$htmlKlasse = ($konto ?? null)?->barrierefrei === true ? ' class="modus-barrierefrei"' : '';
?>
<!DOCTYPE html>
<html lang="de"<?= $htmlKlasse ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($seitentitel) ?></title>
    <?php /* htmx is configured through this meta element and NOT through a
             script: script-src 'self' without 'unsafe-inline' rules inline
             scripts out (CLAUDE.md section 4). includeIndicatorStyles off:
             htmx would otherwise inject a style element, which style-src
             'self' drops silently. selfRequestsOnly: an htmx attribute must
             never send member data to a foreign host. */ ?>
    <meta name="htmx-config" content='{"includeIndicatorStyles":false,"selfRequestsOnly":true}'>
    <?php foreach ($stylesheets as $stylesheet): ?>
    <link rel="stylesheet" href="<?= e($stylesheet) ?>?v=<?= e($version) ?>">
    <?php endforeach; ?>
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <?php /* Only external files (CLAUDE.md section 4); values a script needs
             travel as data-* attributes. */ ?>
    <script src="/js/vendor/htmx.min.js?v=<?= e($version) ?>" defer></script>
    <?php if ($bereich->mitNavigation() && ($konto ?? null) !== null): ?>
    <script src="/js/benutzermenue.js?v=<?= e($version) ?>" defer></script>
    <?php endif; ?>
    <?php foreach (($scripts ?? []) as $skript): ?>
    <script src="<?= e($skript) ?>?v=<?= e($version) ?>" defer></script>
    <?php endforeach; ?>
</head>
<?php /* hx-headers is plain JSON read by htmx, not code - App\Http\Session
         accepts exactly this header, so every htmx POST on a page with a
         session carries the token without a line of JavaScript. */ ?>
<body class="bereich-<?= e($bereich->value) ?>"<?php
    if ($csrfToken !== null) {
        echo ' hx-headers=\'{"X-CSRF-Token":"' . e($csrfToken) . '"}\'';
    }
?>>
<a class="sprungmarke" href="#inhalt">Zum Inhalt springen</a>

<?php require $partialsDir . '/kopf.php'; ?>

<main id="inhalt" class="inhalt">
    <?php require $partialsDir . '/flash.php'; ?>
    <?= $content ?>
</main>

<?php require $partialsDir . '/fuss.php'; ?>
</body>
</html>
