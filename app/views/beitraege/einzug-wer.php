<?php

/**
 * Returns the closure that names the account behind a direct debit
 * (created, confirmed, discarded): " von <Name>", nothing without an
 * account, "einem inzwischen gelöschten Konto" for one deleted since - the
 * run keeps the id (docs/spec/08-beitraege.md section 6). Use it as
 * `$wer = require __DIR__ . '/einzug-wer.php';` and `$wer($einzugNamen, $id)`.
 */

return static fn(array $namen, ?int $id): string => $id === null
    ? ''
    : ' von ' . e(is_string($namen[$id] ?? null) ? $namen[$id] : 'einem inzwischen gelöschten Konto');
