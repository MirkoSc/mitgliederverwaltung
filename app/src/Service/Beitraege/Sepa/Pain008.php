<?php

declare(strict_types=1);

namespace App\Service\Beitraege\Sepa;

use App\Support\Iban;

/**
 * A SEPA core direct debit, ISO 20022 pain.008.001.08 as the German banking
 * industry specifies it (DFÜ-Abkommen Anlage 3, version 3.7;
 * docs/spec/08-beitraege.md section 4): one group header, one payment
 * information block for the club's account, one transaction per annual fee.
 * Built after the pain.001 of Vereinsbelege (App\Service\Bank\Sepa\Pain001).
 *
 * - Local instrument `CORE`, sequence `RCUR` for every debit (since 2016
 *   a first debit needs no `FRST` any more), batch booking `true`: the
 *   club's statement shows one sum per file.
 * - Service level SEPA, charges SLEV, currency EUR only.
 * - The creditor identifier under `CdtrSchmeId` with scheme name `SEPA`.
 * - Creditor's and debtor's bank by BIC when known, else `NOTPROVIDED`
 *   (IBAN only, allowed in SEPA).
 *
 * Pure: builds the XML in memory and checks every rule it can (character
 * set, lengths, IBAN, BIC, creditor identifier, amounts, mandate date); the
 * tests also validate the result against the official schema
 * (tests/Fixtures/sepa/). Nothing is written anywhere.
 */
final class Pain008
{
    public const string NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08';

    /** Highest amount of one SEPA direct debit: 999 999 999.99 EUR. */
    public const int MAX_CENT = 99_999_999_999;

    public const int NAME_LAENGE = 70;

    public const int ZWECK_LAENGE = 140;

    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * @throws \InvalidArgumentException when the order breaks a rule - a
     *         message without values (never a name, IBAN or amount)
     */
    public static function xml(Pain008Auftrag $auftrag): string
    {
        self::pruefe($auftrag);

        $summe = array_sum(array_map(static fn(Pain008Lastschrift $l): int => $l->cent, $auftrag->lastschriften));
        $anzahl = (string) count($auftrag->lastschriften);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $wurzel = $doc->createElementNS(self::NAMESPACE, 'Document');
        $doc->appendChild($wurzel);
        $initiierung = self::kind($doc, $wurzel, 'CstmrDrctDbtInitn');

        $kopf = self::kind($doc, $initiierung, 'GrpHdr');
        self::kind($doc, $kopf, 'MsgId', $auftrag->msgId);
        self::kind($doc, $kopf, 'CreDtTm', $auftrag->erstellt->format('Y-m-d\TH:i:s'));
        self::kind($doc, $kopf, 'NbOfTxs', $anzahl);
        self::kind($doc, $kopf, 'CtrlSum', self::betrag($summe));
        self::kind($doc, self::kind($doc, $kopf, 'InitgPty'), 'Nm', $auftrag->glaeubiger);

        $zahlung = self::kind($doc, $initiierung, 'PmtInf');
        self::kind($doc, $zahlung, 'PmtInfId', $auftrag->pmtInfId);
        self::kind($doc, $zahlung, 'PmtMtd', 'DD');
        self::kind($doc, $zahlung, 'BtchBookg', 'true');
        self::kind($doc, $zahlung, 'NbOfTxs', $anzahl);
        self::kind($doc, $zahlung, 'CtrlSum', self::betrag($summe));
        $art = self::kind($doc, $zahlung, 'PmtTpInf');
        self::kind($doc, self::kind($doc, $art, 'SvcLvl'), 'Cd', 'SEPA');
        self::kind($doc, self::kind($doc, $art, 'LclInstrm'), 'Cd', 'CORE');
        self::kind($doc, $art, 'SeqTp', 'RCUR');
        self::kind($doc, $zahlung, 'ReqdColltnDt', $auftrag->faelligAm->format('Y-m-d'));
        self::kind($doc, self::kind($doc, $zahlung, 'Cdtr'), 'Nm', $auftrag->glaeubiger);
        self::kind($doc, self::kind($doc, self::kind($doc, $zahlung, 'CdtrAcct'), 'Id'), 'IBAN', $auftrag->iban);
        self::institut($doc, self::kind($doc, $zahlung, 'CdtrAgt'), $auftrag->bic);
        self::kind($doc, $zahlung, 'ChrgBr', 'SLEV');
        $schema = self::kind($doc, self::kind($doc, self::kind($doc, self::kind($doc, $zahlung, 'CdtrSchmeId'), 'Id'), 'PrvtId'), 'Othr');
        self::kind($doc, $schema, 'Id', $auftrag->glaeubigerId);
        self::kind($doc, self::kind($doc, $schema, 'SchmeNm'), 'Prtry', 'SEPA');

        foreach ($auftrag->lastschriften as $l) {
            $tx = self::kind($doc, $zahlung, 'DrctDbtTxInf');
            self::kind($doc, self::kind($doc, $tx, 'PmtId'), 'EndToEndId', $l->endToEndId);
            self::kind($doc, $tx, 'InstdAmt', self::betrag($l->cent))->setAttribute('Ccy', 'EUR');
            $mandat = self::kind($doc, self::kind($doc, $tx, 'DrctDbtTx'), 'MndtRltdInf');
            self::kind($doc, $mandat, 'MndtId', $l->mandatsreferenz);
            self::kind($doc, $mandat, 'DtOfSgntr', $l->mandatsdatum->format('Y-m-d'));
            self::institut($doc, self::kind($doc, $tx, 'DbtrAgt'), $l->bic);
            self::kind($doc, self::kind($doc, $tx, 'Dbtr'), 'Nm', $l->name);
            self::kind($doc, self::kind($doc, self::kind($doc, $tx, 'DbtrAcct'), 'Id'), 'IBAN', $l->iban);
            if ($l->verwendungszweck !== '') {
                self::kind($doc, self::kind($doc, $tx, 'RmtInf'), 'Ustrd', $l->verwendungszweck);
            }
        }

        return (string) $doc->saveXML();
    }

    /** Integer cents as the decimal the schema wants: 1234 -> "12.34". */
    public static function betrag(int $cent): string
    {
        return sprintf('%d.%02d', intdiv($cent, 100), $cent % 100);
    }

    public static function istBic(string $bic): bool
    {
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', $bic) === 1;
    }

    /**
     * Shape of a creditor identifier (Gläubiger-ID): country, two check
     * digits, a three-character business code and the national part -
     * DE98ZZZ09999999999. Upper case, no spaces (as the club data stores it).
     */
    public static function istGlaeubigerId(string $id): bool
    {
        return preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/D', $id) === 1;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function pruefe(Pain008Auftrag $auftrag): void
    {
        foreach (['MsgId' => $auftrag->msgId, 'PmtInfId' => $auftrag->pmtInfId] as $feld => $wert) {
            if (!SepaText::istReferenz($wert)) {
                throw new \InvalidArgumentException($feld . ' ist keine gültige SEPA-Referenz.');
            }
        }
        self::pruefeName($auftrag->glaeubiger, 'Der Name des Vereins (Kontoinhaber)');
        if (!self::istIban($auftrag->iban)) {
            throw new \InvalidArgumentException('Die IBAN des Vereins ist ungültig.');
        }
        if ($auftrag->bic !== '' && !self::istBic($auftrag->bic)) {
            throw new \InvalidArgumentException('Die BIC des Vereins ist ungültig.');
        }
        if (!self::istGlaeubigerId($auftrag->glaeubigerId)) {
            throw new \InvalidArgumentException('Die Gläubiger-ID ist ungültig.');
        }
        if ($auftrag->lastschriften === []) {
            throw new \InvalidArgumentException('Der Einzug enthält keine Lastschrift.');
        }
        $erstellt = $auftrag->erstellt->format('Y-m-d');
        $referenzen = [];
        $summe = 0;
        foreach ($auftrag->lastschriften as $l) {
            if (!SepaText::istReferenz($l->endToEndId) || isset($referenzen[$l->endToEndId])) {
                throw new \InvalidArgumentException('Eine Ende-zu-Ende-Referenz ist ungültig oder doppelt.');
            }
            $referenzen[$l->endToEndId] = true;
            if (!SepaText::istReferenz($l->mandatsreferenz)) {
                throw new \InvalidArgumentException('Eine Mandatsreferenz ist ungültig.');
            }
            if ($l->mandatsdatum->format('Y-m-d') > $erstellt) {
                throw new \InvalidArgumentException('Ein Mandatsdatum liegt in der Zukunft.');
            }
            self::pruefeName($l->name, 'Der Name eines Kontoinhabers');
            if (!self::istIban($l->iban)) {
                throw new \InvalidArgumentException('Die IBAN eines Kontoinhabers ist ungültig.');
            }
            if ($l->bic !== '' && !self::istBic($l->bic)) {
                throw new \InvalidArgumentException('Die BIC eines Kontoinhabers ist ungültig.');
            }
            if ($l->cent < 1 || $l->cent > self::MAX_CENT) {
                throw new \InvalidArgumentException('Ein Betrag liegt außerhalb der SEPA-Grenzen.');
            }
            if (strlen($l->verwendungszweck) > self::ZWECK_LAENGE || !SepaText::istZulaessig($l->verwendungszweck)) {
                throw new \InvalidArgumentException('Ein Verwendungszweck ist zu lang oder enthält unzulässige Zeichen.');
            }
            $summe += $l->cent;
        }
        // The control sum has at most 18 digits; far above any club's run.
        if ($summe > 99_999_999_999_999_999) {
            throw new \InvalidArgumentException('Die Summe des Einzugs ist zu groß.');
        }
    }

    /** Valid and already normalised (upper case, no spaces). */
    private static function istIban(string $iban): bool
    {
        return Iban::istGueltig($iban) && Iban::normalisieren($iban) === $iban;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function pruefeName(string $name, string $was): void
    {
        if (trim($name) === '' || strlen($name) > self::NAME_LAENGE || !SepaText::istZulaessig($name)) {
            throw new \InvalidArgumentException($was . ' fehlt, ist zu lang oder enthält unzulässige Zeichen.');
        }
    }

    /** The bank: by BIC when known, else `NOTPROVIDED`. */
    private static function institut(\DOMDocument $doc, \DOMElement $agent, string $bic): void
    {
        $institut = self::kind($doc, $agent, 'FinInstnId');
        if ($bic !== '') {
            self::kind($doc, $institut, 'BICFI', $bic);
        } else {
            self::kind($doc, self::kind($doc, $institut, 'Othr'), 'Id', 'NOTPROVIDED');
        }
    }

    private static function kind(\DOMDocument $doc, \DOMElement $eltern, string $name, ?string $text = null): \DOMElement
    {
        $element = $doc->createElementNS(self::NAMESPACE, $name);
        if ($text !== null) {
            $element->appendChild($doc->createTextNode($text));
        }
        $eltern->appendChild($element);

        return $element;
    }
}
