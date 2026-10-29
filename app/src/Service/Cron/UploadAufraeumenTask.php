<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Service\Upload\UploadService;

/**
 * Removes upload chunks left behind for more than a day (docs/spec/
 * 03-datenhaltung.md): an interrupted upload would otherwise keep its
 * plaintext pieces in var/tmp for good.
 */
final readonly class UploadAufraeumenTask implements CronTask
{
    public const int KEEP_HOURS = 24;

    public function __construct(private UploadService $uploads)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(UploadService::aus($dienste));
    }

    public function name(): string
    {
        return 'uploads_aufraeumen';
    }

    public function istAufraeumen(): bool
    {
        return true;
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->uploads->cleanup($now->modify(sprintf('-%d hours', self::KEEP_HOURS)));
    }
}
