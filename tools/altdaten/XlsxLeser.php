<?php

declare(strict_types=1);

namespace Tools\Altdaten;

/**
 * A deliberately small xlsx reader for the one-off legacy conversion
 * (docs/spec/10-import.md section 3): text and numbers of the FIRST sheet,
 * nothing else - no styles, no formulas, no dates (the old software exports
 * dates as text). Pure PHP on ext-zip and ext-dom.
 */
final class XlsxLeser
{
    private const string NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Rows of the first sheet as lists of strings indexed by column (gaps
     * filled with ''). Rows without any value are left out.
     *
     * @return list<list<string>>
     */
    public static function lesen(string $pfad): array
    {
        if (!is_file($pfad)) {
            throw new \RuntimeException('Datei nicht gefunden: ' . $pfad);
        }
        $zip = new \ZipArchive();
        if ($zip->open($pfad, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Keine gültige xlsx-Datei: ' . $pfad);
        }

        try {
            $blatt = self::blattPfad($zip);
            $xml = $zip->getFromName($blatt);
            if ($xml === false) {
                throw new \RuntimeException('Tabellenblatt fehlt in der xlsx-Datei: ' . $pfad);
            }
            $texte = self::geteilteTexte($zip);

            return self::zeilen(self::dom($xml), $texte);
        } finally {
            $zip->close();
        }
    }

    /** Path inside the zip of the first sheet in workbook order. */
    private static function blattPfad(\ZipArchive $zip): string
    {
        $standard = 'xl/worksheets/sheet1.xml';
        $arbeitsmappe = $zip->getFromName('xl/workbook.xml');
        $beziehungen = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($arbeitsmappe === false || $beziehungen === false) {
            return $standard;
        }

        $blaetter = self::dom($arbeitsmappe)->getElementsByTagName('sheet');
        if ($blaetter->length === 0) {
            return $standard;
        }
        $kennung = $blaetter->item(0)?->getAttributeNS(self::NS_REL, 'id') ?? '';
        foreach (self::dom($beziehungen)->getElementsByTagName('Relationship') as $beziehung) {
            if ($beziehung->getAttribute('Id') !== $kennung) {
                continue;
            }
            $ziel = $beziehung->getAttribute('Target');

            return str_starts_with($ziel, '/') ? ltrim($ziel, '/') : 'xl/' . $ziel;
        }

        return $standard;
    }

    /** @return list<string> */
    private static function geteilteTexte(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $texte = [];
        foreach (self::dom($xml)->getElementsByTagName('si') as $eintrag) {
            $texte[] = self::textVon($eintrag);
        }

        return $texte;
    }

    /** Concatenated <t> of an <si>/<is>, without phonetic hints (<rPh>). */
    private static function textVon(\DOMElement $knoten): string
    {
        $text = '';
        foreach ($knoten->getElementsByTagName('t') as $t) {
            if ($t->parentNode instanceof \DOMElement && $t->parentNode->localName === 'rPh') {
                continue;
            }
            $text .= $t->textContent;
        }

        return $text;
    }

    /**
     * @param list<string> $texte
     * @return list<list<string>>
     */
    private static function zeilen(\DOMDocument $blatt, array $texte): array
    {
        $zeilen = [];
        foreach ($blatt->getElementsByTagName('row') as $zeile) {
            $werte = [];
            $naechste = 0;
            foreach ($zeile->childNodes as $zelle) {
                if (!$zelle instanceof \DOMElement || $zelle->localName !== 'c') {
                    continue;
                }
                $spalte = self::spaltenIndex($zelle->getAttribute('r')) ?? $naechste;
                $naechste = $spalte + 1;
                $werte[$spalte] = self::zellwert($zelle, $texte);
            }
            if ($werte === [] || implode('', $werte) === '') {
                continue;
            }
            $dicht = [];
            for ($i = 0, $ende = max(array_keys($werte)); $i <= $ende; $i++) {
                $dicht[] = $werte[$i] ?? '';
            }
            $zeilen[] = $dicht;
        }

        return $zeilen;
    }

    /** @param list<string> $texte */
    private static function zellwert(\DOMElement $zelle, array $texte): string
    {
        $art = $zelle->getAttribute('t');
        if ($art === 'inlineStr') {
            foreach ($zelle->getElementsByTagName('is') as $roh) {
                return self::textVon($roh);
            }

            return '';
        }
        $wert = '';
        foreach ($zelle->getElementsByTagName('v') as $v) {
            $wert = $v->textContent;
            break;
        }

        return match ($art) {
            's' => $texte[(int) $wert] ?? '',
            'str' => $wert,
            'b' => $wert === '1' ? '1' : '0',
            'e' => '',
            default => self::zahl($wert),
        };
    }

    /** "1001", "1001.0" and "1.001E3" all read as "1001"; "12.5" stays. */
    private static function zahl(string $wert): string
    {
        $wert = trim($wert);
        if ($wert === '' || !is_numeric($wert)) {
            return $wert;
        }
        $zahl = (float) $wert;
        if (floor($zahl) === $zahl && abs($zahl) < 1.0e15) {
            return (string) (int) $zahl;
        }

        return $wert;
    }

    /** "AB12" -> 27 (zero based), null without a usable reference. */
    private static function spaltenIndex(string $referenz): ?int
    {
        if (preg_match('/^([A-Z]+)\d*$/D', $referenz, $m) !== 1) {
            return null;
        }
        $index = 0;
        foreach (str_split($m[1]) as $buchstabe) {
            $index = $index * 26 + (ord($buchstabe) - 64);
        }

        return $index - 1;
    }

    private static function dom(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        // No LIBXML_NOENT / network: the file is local, but never expand entities.
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            throw new \RuntimeException('Die xlsx-Datei enthält ungültiges XML.');
        }

        return $dom;
    }
}
