<?php

declare(strict_types=1);

namespace App\Service\Import;

/** Another request is working on the import chain (its step lock is held). */
final class ImportLaeuftBereits extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der Import arbeitet gerade in einem anderen Fenster. Bitte einen Moment warten und dann fortsetzen.');
    }
}
