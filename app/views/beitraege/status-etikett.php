<?php

/**
 * Returns the closure that shows the status of an annual fee as a label
 * (design system "Etiketten"): offen = to do, im Einzug = waiting for the
 * bank, bezahlt = done. Shared by the fees page, the page of one fee and
 * the member tab; use it as
 * `$statusEtikett = require dirname(__DIR__) . '/beitraege/status-etikett.php';`.
 */

use App\Domain\JahresbeitragStatus;

return static fn(JahresbeitragStatus $status): string => '<span class="etikett ' . match ($status) {
    JahresbeitragStatus::Offen => 'etikett-warnung',
    JahresbeitragStatus::ImEinzug => 'etikett-info',
    JahresbeitragStatus::Bezahlt => 'etikett-ok',
} . '">' . e($status->bezeichnung()) . '</span>';
