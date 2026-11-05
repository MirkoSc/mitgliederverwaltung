<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * IBAN and BIC in the member form (docs/spec/07-mitglieder.md section 1):
 * the bank lookup by Bankleitzahl the form's script asks, and what the
 * server shows without the script - bank name, a BIC that does not fit.
 * The names are made up; the IBANs are the usual examples.
 */
final class BankverbindungTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function vorhanden(array $mehr): Mitglied
    {
        return new MitgliedRepository($this->dienste->pdo())
            ->speichern(new Mitglied(501, 'Musterfrau', ...['vorname' => 'Erika', ...$mehr]), VersionsAnlass::angelegt(), null)->mitglied;
    }

    public function testTheBankOfABankleitzahl(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->get('/app/bank/37040044');

        self::assertSame(200, $antwort->status);
        self::assertSame(['bank' => 'Commerzbank', 'bic' => 'COBADEFFXXX'], json_decode($antwort->body, true));
        self::assertSame(['bank' => null, 'bic' => null], json_decode($this->get('/app/bank/99999999')->body, true));
        self::assertSame(['bank' => null, 'bic' => null], json_decode($this->get('/app/bank/123')->body, true));
    }

    public function testOnlyWhoEditsMembersMayAsk(): void
    {
        $this->als(Rolle::Lesend);

        self::assertSame(403, $this->get('/app/bank/37040044')->status);
    }

    public function testTheFormShowsTheBankAndAWrongBic(): void
    {
        $this->als(Rolle::Vorstand);
        $m = $this->vorhanden(['iban' => 'DE89370400440532013000', 'bic' => 'INGDDEFFXXX']);

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $m->id, 'reiter' => 'daten'])->body;

        self::assertStringContainsString('Bank: Commerzbank', $seite);
        self::assertMatchesRegularExpression('/id="m-bic"[^>]*aria-invalid="true"/', $seite);
        self::assertStringContainsString('zur Bankleitzahl gehört COBADEFFXXX', $seite);
        self::assertStringContainsString('/js/bankverbindung.js', $seite);
    }

    public function testAFittingBicIsNotMarked(): void
    {
        $this->als(Rolle::Vorstand);
        $m = $this->vorhanden(['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFF']);

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $m->id, 'reiter' => 'daten'])->body;

        self::assertDoesNotMatchRegularExpression('/id="m-bic"[^>]*aria-invalid/', $seite);
        self::assertDoesNotMatchRegularExpression('/id="m-iban"[^>]*aria-invalid/', $seite);
    }

    public function testReadOnlySeesTheBankName(): void
    {
        $m = $this->vorhanden(['iban' => 'DE89370400440532013000']);
        $this->als(Rolle::Lesend);

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $m->id, 'reiter' => 'daten'])->body;

        self::assertStringContainsString('Commerzbank', $seite);
    }

    public function testTheNewMemberPageLoadsTheScript(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertStringContainsString('/js/bankverbindung.js', $this->get('/app/mitglieder/neu')->body);
    }
}
