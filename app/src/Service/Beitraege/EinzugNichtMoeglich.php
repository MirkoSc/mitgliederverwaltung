<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

/**
 * A direct debit cannot be made, confirmed or discarded right now
 * (docs/spec/08-beitraege.md sections 3 and 4) - club data missing, nothing
 * chosen, the choice changed meanwhile, already closed. The message is
 * German and meant for the user; it never contains member data.
 */
final class EinzugNichtMoeglich extends \DomainException
{
}
