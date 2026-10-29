<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A change of an annual fee its status does not allow (docs/spec/
 * 08-beitraege.md section 2), e.g. a new amount once it is in a direct
 * debit. The message is German and meant for the user; it never contains
 * member data.
 */
final class BeitragNichtAenderbar extends \DomainException
{
}
