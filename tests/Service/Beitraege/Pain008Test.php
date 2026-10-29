<?php

declare(strict_types=1);

namespace App\Tests\Service\Beitraege;

use App\Service\Beitraege\Sepa\Pain008;
use App\Service\Beitraege\Sepa\Pain008Auftrag;
use App\Service\Beitraege\Sepa\Pain008Lastschrift;
use App\Service\Beitraege\Sepa\SepaText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pain.008.001.08 direct debit file (docs/spec/08-beitraege.md
 * section 4): valid against the official ISO 20022 schema
 * (tests/Fixtures/sepa/), plus the rules of the German banking industry the
 * schema does not carry (CORE, RCUR, SLEV, character set, lengths). All
 * names, IBANs and the creditor identifier are made-up examples.
 */
final class Pain008Test extends TestCase
{
    public const string VEREIN = 'DE89370400440532013000';

    public const string GLAEUBIGER_ID = 'DE98ZZZ09999999999';

    private const string ANNA = 'DE02120300000000202051';

    private const string BERT = 'DE12500105170648489890';

    public function testTheFileIsValidAgainstTheOfficialSchema(): void
    {
        $xml = Pain008::xml($this->auftrag());

        self::assertSame([], self::fehler($xml));
    }

    public function testWithoutAnyBicTheBanksAreNotProvidedAndTheFileStaysValid(): void
    {
        $xml = Pain008::xml($this->auftrag(bic: '', lastschriften: [
            new Pain008Lastschrift('E1-7-2026', 8500, '7', new \DateTimeImmutable('2019-03-01'), 'Anna Mueller', self::ANNA, '', 'Beitrag'),
        ]));

        self::assertSame([], self::fehler($xml));
        $kompakt = (string) preg_replace('/>\s+</', '><', $xml);
        self::assertStringContainsString('<CdtrAgt><FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId></CdtrAgt>', $kompakt);
        self::assertStringContainsString('<DbtrAgt><FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId></DbtrAgt>', $kompakt);
        self::assertStringNotContainsString('BICFI', $kompakt);
    }

    public function testTheFileCarriesSumsCountAndTheGermanRules(): void
    {
        $wert = self::xpath(Pain008::xml($this->auftrag()));

        self::assertSame('2', $wert('//p:GrpHdr/p:NbOfTxs'));
        self::assertSame('1234.57', $wert('//p:GrpHdr/p:CtrlSum'), '1234.56 + 0.01');
        self::assertSame('2', $wert('//p:PmtInf/p:NbOfTxs'));
        self::assertSame('1234.57', $wert('//p:PmtInf/p:CtrlSum'));
        self::assertSame('E12-20260210101500', $wert('//p:GrpHdr/p:MsgId'));
        self::assertSame('2026-02-10T10:15:00', $wert('//p:GrpHdr/p:CreDtTm'));
        self::assertSame('TSV Beispiel e.V.', $wert('//p:GrpHdr/p:InitgPty/p:Nm'));
        self::assertSame('E12', $wert('//p:PmtInf/p:PmtInfId'));
        self::assertSame('DD', $wert('//p:PmtInf/p:PmtMtd'));
        self::assertSame('true', $wert('//p:PmtInf/p:BtchBookg'));
        self::assertSame('SEPA', $wert('//p:PmtTpInf/p:SvcLvl/p:Cd'));
        self::assertSame('CORE', $wert('//p:PmtTpInf/p:LclInstrm/p:Cd'));
        self::assertSame('RCUR', $wert('//p:PmtTpInf/p:SeqTp'));
        self::assertSame('2026-02-13', $wert('//p:PmtInf/p:ReqdColltnDt'));
        self::assertSame('TSV Beispiel e.V.', $wert('//p:PmtInf/p:Cdtr/p:Nm'));
        self::assertSame(self::VEREIN, $wert('//p:CdtrAcct/p:Id/p:IBAN'));
        self::assertSame('COBADEFFXXX', $wert('//p:CdtrAgt/p:FinInstnId/p:BICFI'));
        self::assertSame('SLEV', $wert('//p:PmtInf/p:ChrgBr'));
        self::assertSame(self::GLAEUBIGER_ID, $wert('//p:CdtrSchmeId/p:Id/p:PrvtId/p:Othr/p:Id'));
        self::assertSame('SEPA', $wert('//p:CdtrSchmeId/p:Id/p:PrvtId/p:Othr/p:SchmeNm/p:Prtry'));

        self::assertSame('E12-1001-2026', $wert('//p:DrctDbtTxInf[1]/p:PmtId/p:EndToEndId'));
        self::assertSame('1234.56', $wert('//p:DrctDbtTxInf[1]/p:InstdAmt'));
        self::assertSame('EUR', $wert('//p:DrctDbtTxInf[1]/p:InstdAmt/@Ccy'));
        self::assertSame('1001', $wert('//p:DrctDbtTxInf[1]/p:DrctDbtTx/p:MndtRltdInf/p:MndtId'));
        self::assertSame('2015-05-01', $wert('//p:DrctDbtTxInf[1]/p:DrctDbtTx/p:MndtRltdInf/p:DtOfSgntr'));
        self::assertSame('Anna Mueller', $wert('//p:DrctDbtTxInf[1]/p:Dbtr/p:Nm'));
        self::assertSame(self::ANNA, $wert('//p:DrctDbtTxInf[1]/p:DbtrAcct/p:Id/p:IBAN'));
        self::assertSame('BYLADEM1001', $wert('//p:DrctDbtTxInf[1]/p:DbtrAgt/p:FinInstnId/p:BICFI'));
        self::assertSame('Mitgliedsbeitrag 2026 Nr. 1001 Anna Mueller', $wert('//p:DrctDbtTxInf[1]/p:RmtInf/p:Ustrd'));
        self::assertSame('0.01', $wert('//p:DrctDbtTxInf[2]/p:InstdAmt'));
        self::assertSame('NOTPROVIDED', $wert('//p:DrctDbtTxInf[2]/p:DbtrAgt/p:FinInstnId/p:Othr/p:Id'));
        self::assertSame('', $wert('//p:DrctDbtTxInf[2]/p:RmtInf'), 'no remittance element without text');
    }

    public function testTransliteratedTextKeepsTheFileValid(): void
    {
        $name = SepaText::bereinigen('Jürgen Müller-Österle & Söhne', Pain008::NAME_LAENGE);
        $zweck = SepaText::bereinigen('Mitgliedsbeitrag 2026 Nr. 7 Jürgen Weiß – „Familie“', Pain008::ZWECK_LAENGE);
        $xml = Pain008::xml($this->auftrag(glaeubiger: SepaText::bereinigen('TSV Großbeispiel 1899 e.V.', 70), lastschriften: [
            new Pain008Lastschrift('E1-7-2026', 13000, '7', new \DateTimeImmutable('2019-03-01'), $name, self::ANNA, '', $zweck),
        ]));

        self::assertSame([], self::fehler($xml));
        $wert = self::xpath($xml);
        self::assertSame('Juergen Mueller-Oesterle + Soehne', $wert('//p:Dbtr/p:Nm'));
        self::assertSame("Mitgliedsbeitrag 2026 Nr. 7 Juergen Weiss - 'Familie'", $wert('//p:RmtInf/p:Ustrd'));
        self::assertSame('TSV Grossbeispiel 1899 e.V.', $wert('//p:Cdtr/p:Nm'));
    }

    public function testAmountsAreIntegerCentsAsDecimal(): void
    {
        self::assertSame('0.05', Pain008::betrag(5));
        self::assertSame('85.00', Pain008::betrag(8500));
        self::assertSame('70.83', Pain008::betrag(7083));
        self::assertSame('999999999.99', Pain008::betrag(Pain008::MAX_CENT));
    }

    public function testTheShapeOfACreditorIdentifier(): void
    {
        self::assertTrue(Pain008::istGlaeubigerId(self::GLAEUBIGER_ID));
        self::assertTrue(Pain008::istGlaeubigerId('AT61ZZZ01234567890'));
        self::assertFalse(Pain008::istGlaeubigerId(''));
        self::assertFalse(Pain008::istGlaeubigerId('DE98 ZZZ 09999999999'));
        self::assertFalse(Pain008::istGlaeubigerId('de98zzz09999999999'));
        self::assertFalse(Pain008::istGlaeubigerId('DEXXZZZ09999999999'));
        self::assertFalse(Pain008::istGlaeubigerId('DE98ZZZ'));
    }

    /**
     * @return iterable<string, array{\Closure(self): Pain008Auftrag, string}>
     */
    public static function ungueltigeAuftraege(): iterable
    {
        $tx = static fn(string $e2e = 'E1-1-2026', int $cent = 100, string $iban = self::ANNA, string $mandat = '1', string $datum = '2019-03-01', string $name = 'Anna', string $bic = '', string $zweck = ''): Pain008Lastschrift
            => new Pain008Lastschrift($e2e, $cent, $mandat, new \DateTimeImmutable($datum), $name, $iban, $bic, $zweck);

        yield 'no debit' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: []), 'keine Lastschrift'];
        yield 'club IBAN' => [static fn(self $t): Pain008Auftrag => $t->auftrag(iban: 'DE00370400440532013000'), 'IBAN des Vereins'];
        yield 'club IBAN not normalised' => [static fn(self $t): Pain008Auftrag => $t->auftrag(iban: 'DE89 3704 0044 0532 0130 00'), 'IBAN des Vereins'];
        yield 'club BIC' => [static fn(self $t): Pain008Auftrag => $t->auftrag(bic: 'COBA'), 'BIC des Vereins'];
        yield 'creditor identifier' => [static fn(self $t): Pain008Auftrag => $t->auftrag(glaeubigerId: ''), 'Gläubiger-ID'];
        yield 'club name missing' => [static fn(self $t): Pain008Auftrag => $t->auftrag(glaeubiger: ''), 'Name des Vereins'];
        yield 'umlaut left' => [static fn(self $t): Pain008Auftrag => $t->auftrag(glaeubiger: 'TSV Müller'), 'Name des Vereins'];
        yield 'debtor IBAN' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(iban: 'DE02120300000000202052')]), 'IBAN eines Kontoinhabers'];
        yield 'debtor BIC' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(bic: 'XX')]), 'BIC eines Kontoinhabers'];
        yield 'debtor name too long' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(name: str_repeat('A', 71))]), 'Name eines Kontoinhabers'];
        yield 'zero' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(cent: 0)]), 'SEPA-Grenzen'];
        yield 'too much' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(cent: Pain008::MAX_CENT + 1)]), 'SEPA-Grenzen'];
        yield 'remittance too long' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(zweck: str_repeat('x', 141))]), 'Verwendungszweck'];
        yield 'mandate reference' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(mandat: '/12')]), 'Mandatsreferenz'];
        yield 'mandate in the future' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(datum: '2026-02-11')]), 'Mandatsdatum'];
        yield 'same reference twice' => [static fn(self $t): Pain008Auftrag => $t->auftrag(lastschriften: [$tx(), $tx(iban: self::BERT)]), 'doppelt'];
        yield 'message id' => [static fn(self $t): Pain008Auftrag => $t->auftrag(msgId: 'A//B'), 'MsgId'];
    }

    /**
     * @param \Closure(self): Pain008Auftrag $auftrag
     */
    #[DataProvider('ungueltigeAuftraege')]
    public function testABrokenOrderIsRefusedWithoutValuesInTheMessage(\Closure $auftrag, string $meldung): void
    {
        try {
            Pain008::xml($auftrag($this));
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($meldung, $e->getMessage());
            foreach ([self::ANNA, self::VEREIN, self::GLAEUBIGER_ID, 'Anna', '1.00'] as $wert) {
                self::assertStringNotContainsString($wert, $e->getMessage());
            }
        }
    }

    /**
     * @param list<Pain008Lastschrift>|null $lastschriften
     */
    public function auftrag(
        string $iban = self::VEREIN,
        string $bic = 'COBADEFFXXX',
        string $glaeubiger = 'TSV Beispiel e.V.',
        string $glaeubigerId = self::GLAEUBIGER_ID,
        ?array $lastschriften = null,
        string $msgId = 'E12-20260210101500',
    ): Pain008Auftrag {
        return new Pain008Auftrag(
            $msgId,
            'E12',
            new \DateTimeImmutable('2026-02-10 10:15:00'),
            $glaeubiger,
            $iban,
            $bic,
            $glaeubigerId,
            new \DateTimeImmutable('2026-02-13'),
            $lastschriften ?? [
                new Pain008Lastschrift('E12-1001-2026', 123456, '1001', new \DateTimeImmutable('2015-05-01'), 'Anna Mueller', self::ANNA, 'BYLADEM1001', 'Mitgliedsbeitrag 2026 Nr. 1001 Anna Mueller'),
                new Pain008Lastschrift('E12-1002-2026', 1, '1002', new \DateTimeImmutable('2026-02-10'), 'Bert Beispiel', self::BERT, '', ''),
            ],
        );
    }

    /**
     * @return \Closure(string): string an XPath expression to its string value
     */
    public static function xpath(string $xml): \Closure
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('p', Pain008::NAMESPACE);

        return static fn(string $pfad): string => (string) $xpath->evaluate('string(' . $pfad . ')');
    }

    /**
     * The schema errors of $xml; [] = valid against pain.008.001.08.
     *
     * @return list<string>
     */
    public static function fehler(string $xml): array
    {
        $vorher = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->loadXML($xml);
            $ok = $doc->schemaValidate(dirname(__DIR__, 2) . '/Fixtures/sepa/pain.008.001.08.xsd');
            $fehler = array_map(static fn(\LibXMLError $e): string => trim($e->message), libxml_get_errors());
            libxml_clear_errors();

            return $ok ? [] : ($fehler === [] ? ['invalid'] : $fehler);
        } finally {
            libxml_use_internal_errors($vorher);
        }
    }
}
