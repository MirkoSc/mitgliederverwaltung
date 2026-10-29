<?php

/**
 * Returns the closure that shows the status of a direct debit as a label
 * (design system "Etiketten"): erzeugt = waiting for the bank, bestätigt =
 * done, verworfen = never sent. Use it as
 * `$einzugEtikett = require __DIR__ . '/einzug-etikett.php';`.
 */

use App\Domain\EinzugStatus;

return static fn(EinzugStatus $status): string => '<span class="etikett' . match ($status) {
    EinzugStatus::Erzeugt => ' etikett-info',
    EinzugStatus::Bestaetigt => ' etikett-ok',
    EinzugStatus::Verworfen => '',
} . '">' . e($status->bezeichnung()) . '</span>';
