<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The file offered for a restore is no encrypted backup of this application
 * (wrong file, a plain ZIP, an unknown format) - App\Service\Backup\BackupCipher.
 */
final class KeinBackup extends \RuntimeException
{
}
