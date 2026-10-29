<?php

/**
 * Settings section System: the two checks load themselves (htmx), so the
 * settings page does not wait for the probe of the own domain.
 */
?>
<h3>Systemcheck</h3>
<p class="gedaempft klein">Prüft, was sich beim Hoster ändern kann: PHP-Einstellungen, Erweiterungen, Schreibrechte und
    Zugriffssperren.</p>
<div id="systemcheck" hx-get="/admin/systemcheck" hx-trigger="load" hx-swap="innerHTML">
    <p class="gedaempft" role="status">Systemcheck läuft …</p>
</div>

<h3>Integritätscheck</h3>
<p class="gedaempft klein">Vergleicht jede Datei des installierten Releases mit der Prüfsummenliste des Releases.</p>
<div id="integritaet" hx-get="/admin/integritaet" hx-trigger="load" hx-swap="innerHTML">
    <p class="gedaempft" role="status">Wird geladen …</p>
</div>
