<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The key given for a restore does not open the backup: the wrapped file key
 * in its head fails to decrypt (App\Service\Backup\BackupCipher). Said before
 * a single byte is restored.
 */
final class FalscherSchluessel extends \RuntimeException
{
}
