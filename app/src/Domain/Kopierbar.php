<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * `$wert->mit(['ort' => 'Neustadt'])`: a copy of an immutable domain value
 * with some properties changed, by property name. PHP 8.5 clone-with -
 * which readonly properties only allow from inside the class, hence this
 * trait instead of `clone($wert, [...])` at the caller.
 */
trait Kopierbar
{
    /**
     * @param array<string, mixed> $aenderungen
     */
    public function mit(array $aenderungen): static
    {
        return clone($this, $aenderungen);
    }
}
