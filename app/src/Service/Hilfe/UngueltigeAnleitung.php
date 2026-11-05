<?php

declare(strict_types=1);

namespace App\Service\Hilfe;

/**
 * A guide file breaks a rule of docs/spec/12-hilfe.md section 2. A mistake
 * of whoever wrote the file, caught by the tests (AnleitungenTest) long
 * before a release - so the help fails loudly instead of guessing.
 */
final class UngueltigeAnleitung extends \RuntimeException
{
    public static function in(string $datei, string $grund): self
    {
        return new self(sprintf('Help guide %s: %s', basename($datei), $grund));
    }
}
