<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The member number is already used by another member (07 section 1: unique).
 * Nothing was written. The message carries the number only, no names.
 */
final class MitgliedsnummerVergebenException extends \RuntimeException
{
    public function __construct(public readonly int $mitgliedsnummer)
    {
        parent::__construct(sprintf('Member number %d is already taken.', $mitgliedsnummer));
    }
}
