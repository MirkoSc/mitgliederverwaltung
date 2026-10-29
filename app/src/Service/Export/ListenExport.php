<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\AuditAktion;
use App\Domain\Konto;
use App\Http\StreamResponse;
use App\Listen\ListenAnsicht;
use App\Service\Audit\AuditLog;

/**
 * The export of a list (docs/spec/09-listen-und-exporte.md section 1): the
 * file is produced while it is downloaded, never stored on the webspace, and
 * every export is noted in the audit log - who, when, which list, how many
 * rows. The note carries counters and the list's key, no member data.
 */
final readonly class ListenExport
{
    public function __construct(private AuditLog $audit)
    {
    }

    public function antwort(ListenAnsicht $ansicht, ExportFormat $format, Konto $konto, string $ip = ''): StreamResponse
    {
        $spalten = $ansicht->ergebnis->spalten;
        $zeilen = $ansicht->zeilen;

        // Noted before the first byte leaves: an export that was started is one.
        $this->audit->protokollieren(
            AuditAktion::ExportErzeugt,
            $konto->id,
            'liste',
            $ansicht->liste->schluessel(),
            ['format' => $format->value, 'zeilen' => count($zeilen)],
            $ip,
        );

        $dateiname = self::dateiname($ansicht->liste->dateiname($ansicht->filter), $ansicht->liste->schluessel()) . '.' . $format->value;
        $kopfzeilen = [
            'Content-Type' => $format->mimeType(),
            'Content-Disposition' => 'attachment; filename="' . $dateiname . '"',
            'Cache-Control' => 'no-store',
        ];

        $stuecke = match ($format) {
            ExportFormat::Csv => CsvWriter::stuecke($spalten, $zeilen),
            ExportFormat::Xlsx => self::inStuecken(XlsxWriter::erzeugen($spalten, $zeilen, $ansicht->liste->titel())),
        };

        return new StreamResponse($stuecke, $kopfzeilen);
    }

    /** Lowercase letters, digits and hyphens only; the list's key when nothing is left. */
    public static function dateiname(string $wunsch, string $ersatz): string
    {
        $name = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($wunsch)) ?? '', '-');

        return $name === '' ? $ersatz : $name;
    }

    /** @return \Generator<int, string> */
    private static function inStuecken(string $datei): \Generator
    {
        foreach (str_split($datei, 65536) as $stueck) {
            yield $stueck;
        }
    }
}
