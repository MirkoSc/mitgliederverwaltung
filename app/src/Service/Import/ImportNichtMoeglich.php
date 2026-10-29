<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * The import cannot do what was asked right now (stock not empty, file with
 * errors, annual fees exist ...). The message says what to do, in German, and
 * holds no member data - the page shows it as it is.
 */
final class ImportNichtMoeglich extends \RuntimeException
{
}
