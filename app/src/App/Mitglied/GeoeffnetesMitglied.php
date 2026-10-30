<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Domain\Mitglied;

/**
 * The answer of MitgliedAnsicht for one request: the member (or none), and
 * the rendered right half of "Liste neben Mitglied" (`<div
 * class="geteilt-detail">`), which the embedding page puts beside its list.
 */
final readonly class GeoeffnetesMitglied
{
    /**
     * @param bool $unbekannt a member was asked for that does not exist (any more)
     * @param string|null $reiter the key of the tab shown
     * @param string $html the detail, empty div without a member
     */
    public function __construct(
        public ?Mitglied $mitglied,
        public bool $unbekannt,
        public ?string $reiter,
        public string $html,
    ) {
    }

    public function istOffen(): bool
    {
        return $this->mitglied !== null;
    }

    /** The id of the opened member, null without one. */
    public function id(): ?int
    {
        return $this->mitglied?->id;
    }

    /** The class the embedding `geteilt` container adds: the server marks an opened member. */
    public function klasse(): string
    {
        return $this->istOffen() ? ' geteilt-mit-detail' : '';
    }
}
