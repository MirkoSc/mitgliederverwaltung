<?php

declare(strict_types=1);

namespace App\Service\Hilfe;

/** The picture of a step, ready for the <img> element (12 section 3). */
final readonly class Bild
{
    public function __construct(
        /** Below the docroot, with ?v=<version>. */
        public string $url,
        public string $alt,
        public int $breite,
        public int $hoehe,
    ) {
    }
}
