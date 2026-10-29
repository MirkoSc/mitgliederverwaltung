<?php

declare(strict_types=1);

namespace App\Service\Export;

/** The file formats of a list export (docs/spec/09-listen-und-exporte.md section 1). */
enum ExportFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function bezeichnung(): string
    {
        return match ($this) {
            self::Xlsx => 'Excel-Datei (xlsx)',
            self::Csv => 'CSV-Datei (csv)',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv; charset=utf-8',
        };
    }
}
