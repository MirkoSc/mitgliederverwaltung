<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * A user administration step that the rules do not allow (the last admin,
 * the own account, an address in use). The message is German and meant for
 * the admin's screen; it never names another account's address.
 */
final class BenutzerRegelVerstoss extends \RuntimeException
{
}
