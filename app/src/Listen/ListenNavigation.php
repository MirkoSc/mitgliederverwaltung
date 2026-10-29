<?php

declare(strict_types=1);

namespace App\Listen;

/**
 * "Zurück / Vor" through a period (birthdays by week, month or year): a
 * title for the current period and the filter values that lead to the
 * neighbours.
 */
final readonly class ListenNavigation
{
    /**
     * @param string $titel the current period, e.g. "März 2027"
     * @param array<string, string|int|bool> $zurueck filter values of the previous period
     * @param array<string, string|int|bool> $vor filter values of the next period
     */
    public function __construct(
        public string $titel,
        public array $zurueck,
        public array $vor,
    ) {
    }
}
