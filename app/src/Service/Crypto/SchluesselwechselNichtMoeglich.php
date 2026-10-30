<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Why the key change cannot start right now - a sentence for the admin page
 * (a backup is being made, config.php cannot be written).
 */
final class SchluesselwechselNichtMoeglich extends \RuntimeException
{
}
