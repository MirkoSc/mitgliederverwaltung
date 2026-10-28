<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The note "geehrt" (docs/spec/09-listen-und-exporte.md section 2): a member
 * was honoured for a level (years of membership); at most once per level,
 * and it can be taken back.
 */
final readonly class Ehrung
{
    public function __construct(
        public int $mitgliedId,
        public int $stufeJahre,
        public \DateTimeImmutable $geehrtAm,
        public ?int $vermerktVon = null,
        public ?int $id = null,
    ) {
    }
}
