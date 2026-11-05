<?php

declare(strict_types=1);

namespace App\Service\Hilfe;

/**
 * One step of a guide (docs/spec/12-hilfe.md section 2): one action.
 * `text` and `tipp` are plain text with **bold** as the only markup;
 * `bild` is the alternative text of the step's picture.
 */
final readonly class Schritt
{
    public function __construct(
        /** 1, 2, 3 ... - also the number in the picture's file name. */
        public int $nummer,
        public string $text,
        public ?string $bild = null,
        public ?string $tipp = null,
    ) {
    }

    /** "01", "02" ... as in public/img/hilfe/<slug>/<NN>-normal.png. */
    public function bildNummer(): string
    {
        return sprintf('%02d', $this->nummer);
    }
}
