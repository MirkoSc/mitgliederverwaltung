<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * Which kind of code just confirmed a second factor
 * (App\Service\Account\ZweiterFaktor::pruefe()). The login treats a backup
 * code differently: it remembers no device and says how many are left.
 */
enum Nachweis
{
    case App;
    case Email;
    case BackupCode;
}
