<?php

declare(strict_types=1);

namespace App\Tests\Service\Mitglied;

use App\Domain\AktivArt;
use App\Domain\BeitragsRegel;
use App\Domain\Beitragsart;
use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Mitglied;
use App\Domain\Nachweis;
use App\Domain\NachweisArt;
use App\Domain\Zahlart;
use App\Service\Karte\GeoStatus;
use App\Service\Mitglied\HinweisArt;
use App\Service\Mitglied\HinweisKontext;
use App\Service\Mitglied\Hinweise;
use PHPUnit\Framework\TestCase;

/**
 * Every rule of docs/spec/07-mitglieder.md section 6, positive and negative,
 * with the border cases (exactly 18, exactly 67, a proof valid until today).
 * Made-up data only.
 */
final class HinweiseTest extends TestCase
{
    private const string HEUTE = '2026-06-15';

    private const string IBAN = 'DE89370400440532013000';

    private const int RENTNER = 1;
    private const int JUGEND = 2;
    private const int FAMILIE = 3;
    private const int FREI = 4;
    private const int AKTIV = 5;
    private const int PASSIV = 6;
    private const int NORMAL = 7;

    private static function tag(string $datum = self::HEUTE): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datum);
    }

    /** @return list<Beitragsart> */
    private static function beitragsarten(): array
    {
        return [
            new Beitragsart('Rentner aktiv', 5000, BeitragsRegel::Rentner, id: self::RENTNER),
            new Beitragsart('Jugend', 4500, BeitragsRegel::Jugend, id: self::JUGEND),
            new Beitragsart('Familie', 13000, BeitragsRegel::Familie, id: self::FAMILIE),
            new Beitragsart('Beitragsfrei', 0, BeitragsRegel::Beitragsfrei, id: self::FREI),
            new Beitragsart('Aktiv', 8500, aktivArt: AktivArt::Aktiv, id: self::AKTIV),
            new Beitragsart('Passiv', 5000, aktivArt: AktivArt::Passiv, id: self::PASSIV),
            new Beitragsart('Mindestbeitrag', 2500, id: self::NORMAL),
        ];
    }

    /**
     * A member without any finding: the base every test changes one thing on.
     *
     * @param array<string, mixed> $aenderungen
     */
    private static function mitglied(array $aenderungen = []): Mitglied
    {
        return new Mitglied(
            1001,
            'Mustermann',
            vorname: 'Erika',
            geschlecht: Geschlecht::W,
            geburtsdatum: new \DateTimeImmutable('1980-03-10'),
            eintrittsdatum: new \DateTimeImmutable('2001-01-01'),
            beitragsartId: self::NORMAL,
            iban: self::IBAN,
            id: 1,
        )->mit($aenderungen);
    }

    /**
     * @param list<Mitglied> $familie
     * @param list<Nachweis> $nachweise
     * @return list<HinweisArt>
     */
    private static function arten(Mitglied $m, array $familie = [], array $nachweise = [], string $tag = self::HEUTE): array
    {
        return array_map(
            static fn($hinweis): HinweisArt => $hinweis->art,
            Hinweise::fuer($m, new HinweisKontext(self::tag($tag), $familie, $nachweise, self::beitragsarten())),
        );
    }

    private static function geboren(string $datum): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datum);
    }

    /** Born so that the age on HEUTE is exactly $jahre, the birthday being that day. */
    private static function alt(int $jahre): \DateTimeImmutable
    {
        return self::geboren((2026 - $jahre) . '-06-15');
    }

    private static function person(int $id, FamilienRolle $rolle, array $mehr = []): Mitglied
    {
        return self::mitglied([
            'id' => $id,
            'mitgliedsnummer' => 2000 + $id,
            'familieId' => 5,
            'familieRolle' => $rolle,
            'beitragsartId' => $rolle === FamilienRolle::Zahler ? self::FAMILIE : null,
            'strasse' => 'Lindenweg 3',
            'plz' => '12345',
            'geburtsdatum' => $rolle === FamilienRolle::Kind ? self::alt(10) : self::geboren('1980-03-10'),
            ...$mehr,
        ]);
    }

    public function testAMemberWithNothingToReportHasNoHints(): void
    {
        self::assertSame([], self::arten(self::mitglied()));
    }

    public function testHintsComeInTheOrderOfTheSpecTable(): void
    {
        $alles = self::mitglied([
            'beitragsartId' => null,
            'geschlecht' => null,
            'eintrittsdatum' => null,
            'iban' => '',
        ]);

        self::assertSame(
            [HinweisArt::KeinBeitrag, HinweisArt::LastschriftOhneIban, HinweisArt::EintrittsdatumUnbekannt, HinweisArt::BlsvAngabenFehlen],
            self::arten($alles),
        );
    }

    // Rentner unter 67

    public function testPensionerFeeBelow67IsHinted(): void
    {
        $nachweis = [new Nachweis(1, NachweisArt::Rente)];

        self::assertSame([HinweisArt::RentnerUnter67], self::arten(self::mitglied(['beitragsartId' => self::RENTNER, 'geburtsdatum' => self::alt(66)]), nachweise: $nachweis));
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::RENTNER, 'geburtsdatum' => self::alt(67)]), nachweise: $nachweis), 'exactly 67 is fine');
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::RENTNER, 'geburtsdatum' => self::alt(80)]), nachweise: $nachweis));
    }

    public function testTheDayBeforeThe67thBirthdayIsStillBelow67(): void
    {
        $nachweis = [new Nachweis(1, NachweisArt::Rente)];
        $geboren = self::geboren('1959-06-16'); // turns 67 tomorrow

        self::assertSame([HinweisArt::RentnerUnter67], self::arten(self::mitglied(['beitragsartId' => self::RENTNER, 'geburtsdatum' => $geboren]), nachweise: $nachweis));
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::RENTNER, 'geburtsdatum' => $geboren]), nachweise: $nachweis, tag: '2026-06-16'));
    }

    public function testPensionerRuleNeedsTheRuleAndABirthDate(): void
    {
        $nachweis = [new Nachweis(1, NachweisArt::Rente)];

        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::NORMAL, 'geburtsdatum' => self::alt(30)]), nachweise: $nachweis), 'no pensioner rule');
        self::assertSame([HinweisArt::BlsvAngabenFehlen], self::arten(self::mitglied(['beitragsartId' => self::RENTNER, 'geburtsdatum' => null]), nachweise: $nachweis), 'age unknown: no age hint');
    }

    // Rentennachweis fehlt

    public function testPensionerFeeWithoutAValidProofIsHinted(): void
    {
        $rentner = self::mitglied(['beitragsartId' => self::RENTNER, 'geburtsdatum' => self::alt(70)]);

        self::assertSame([HinweisArt::RentennachweisFehlt], self::arten($rentner));
        self::assertSame([], self::arten($rentner, nachweise: [new Nachweis(1, NachweisArt::Rente)]));
        self::assertSame([], self::arten($rentner, nachweise: [new Nachweis(1, NachweisArt::Rente, gueltigBis: self::tag())]), 'valid until today is valid');
        self::assertSame(
            [HinweisArt::RentennachweisFehlt],
            self::arten($rentner, nachweise: [new Nachweis(1, NachweisArt::Studium)]),
            'a proof of another kind does not count',
        );
        self::assertSame(
            [HinweisArt::RentennachweisFehlt, HinweisArt::NachweisAbgelaufen],
            self::arten($rentner, nachweise: [new Nachweis(1, NachweisArt::Rente, gueltigBis: self::tag('2026-06-14'))]),
            'an expired proof is no valid proof',
        );
        self::assertSame(
            [HinweisArt::NachweisAbgelaufen],
            self::arten($rentner, nachweise: [
                new Nachweis(1, NachweisArt::Rente, gueltigBis: self::tag('2020-01-01')),
                new Nachweis(1, NachweisArt::Rente),
            ]),
            'one valid proof is enough',
        );
    }

    // Jugendbeitrag ab 18 ohne Nachweis

    public function testYouthFeeFrom18WithoutProofIsHinted(): void
    {
        $jugend = static fn(int $alter): Mitglied => self::mitglied(['beitragsartId' => self::JUGEND, 'geburtsdatum' => self::alt($alter)]);

        self::assertSame([], self::arten($jugend(17)));
        self::assertSame([HinweisArt::JugendbeitragAb18], self::arten($jugend(18)), 'exactly 18 is hinted');
        self::assertSame([HinweisArt::JugendbeitragAb18], self::arten($jugend(25)));
    }

    public function testYouthFeeFrom18IsFineWithAValidStudyOrApprenticeshipProof(): void
    {
        $jugend = self::mitglied(['beitragsartId' => self::JUGEND, 'geburtsdatum' => self::alt(20)]);

        self::assertSame([], self::arten($jugend, nachweise: [new Nachweis(1, NachweisArt::Studium)]));
        self::assertSame([], self::arten($jugend, nachweise: [new Nachweis(1, NachweisArt::Ausbildung, gueltigBis: self::tag())]));
        self::assertSame([HinweisArt::JugendbeitragAb18], self::arten($jugend, nachweise: [new Nachweis(1, NachweisArt::Rente)]));
        self::assertSame([HinweisArt::JugendbeitragAb18], self::arten($jugend, nachweise: [new Nachweis(1, NachweisArt::Sonstiges)]));
        self::assertSame(
            [HinweisArt::JugendbeitragAb18, HinweisArt::NachweisAbgelaufen],
            self::arten($jugend, nachweise: [new Nachweis(1, NachweisArt::Studium, gueltigBis: self::tag('2026-06-14'))]),
        );
    }

    // Nachweis abgelaufen

    public function testAProofPastItsEndDateIsHintedButNotOnTheDayItself(): void
    {
        $m = self::mitglied();

        self::assertSame([], self::arten($m, nachweise: [new Nachweis(1, NachweisArt::Sonstiges, gueltigBis: self::tag())]), 'valid until today');
        self::assertSame([HinweisArt::NachweisAbgelaufen], self::arten($m, nachweise: [new Nachweis(1, NachweisArt::Sonstiges, gueltigBis: self::tag('2026-06-14'))]));
        self::assertSame([], self::arten($m, nachweise: [new Nachweis(1, NachweisArt::Sonstiges)]), 'no end date never expires');
    }

    public function testEveryExpiredProofGetsItsOwnHintWithKindAndDate(): void
    {
        $hinweise = Hinweise::fuer(self::mitglied(), new HinweisKontext(self::tag(), [], [
            new Nachweis(1, NachweisArt::Studium, gueltigBis: self::tag('2026-03-31')),
            new Nachweis(1, NachweisArt::Sonstiges, gueltigBis: self::tag('2025-12-31')),
        ], self::beitragsarten()));

        self::assertCount(2, $hinweise);
        self::assertStringContainsString('Studium', $hinweise[0]->text);
        self::assertStringContainsString('31.03.2026', $hinweise[0]->text);
        self::assertStringContainsString('31.12.2025', $hinweise[1]->text);
    }

    // Aktiv/passiv passt nicht zur Beitragsart

    public function testActiveAndPassiveMustFitTheFeeType(): void
    {
        self::assertSame([HinweisArt::AktivPassivPasstNicht], self::arten(self::mitglied(['beitragsartId' => self::AKTIV, 'aktiv' => false])));
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::AKTIV, 'aktiv' => true])));
        self::assertSame([HinweisArt::AktivPassivPasstNicht], self::arten(self::mitglied(['beitragsartId' => self::PASSIV, 'aktiv' => true])));
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::PASSIV, 'aktiv' => false])));
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::NORMAL, 'aktiv' => false])), 'egal fits both');
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::NORMAL, 'aktiv' => true])));
    }

    // Familie zu klein

    public function testAFamilyNeedsTwoPeople(): void
    {
        $zahler = self::person(1, FamilienRolle::Zahler);
        $kind = self::person(2, FamilienRolle::Kind);

        self::assertSame([HinweisArt::FamilieZuKlein], self::arten($zahler, [$zahler]));
        self::assertSame([], self::arten($zahler, [$zahler, $kind]));
        self::assertSame([], self::arten(self::mitglied()), 'no family, no hint');
    }

    public function testTheMemberGivenReplacesTheStoredOneInTheFamily(): void
    {
        $gespeichert = self::person(1, FamilienRolle::Zahler);
        $kind = self::person(2, FamilienRolle::Kind);
        // Edited, not stored yet: the payer role is gone.
        $geaendert = $gespeichert->mit(['familieRolle' => FamilienRolle::Elternteil, 'beitragsartId' => null]);

        self::assertSame([], self::arten($gespeichert, [$gespeichert, $kind]));
        self::assertSame([HinweisArt::FamilieZahlerAnzahl], self::arten($geaendert, [$gespeichert, $kind]));
        self::assertSame([HinweisArt::FamilieZuKlein], self::arten($gespeichert, []), 'the member itself counts even when the stored family is empty');
    }

    // Familie ohne bzw. mit mehreren Zahlern

    public function testAFamilyHasExactlyOnePayer(): void
    {
        $eltern = self::person(1, FamilienRolle::Elternteil);
        $kind = self::person(2, FamilienRolle::Kind);
        $zahler = self::person(3, FamilienRolle::Zahler);
        $zahler2 = self::person(4, FamilienRolle::Zahler);

        self::assertSame([HinweisArt::FamilieZahlerAnzahl], self::arten($kind, [$eltern, $kind]), 'none');
        self::assertSame([], self::arten($kind, [$zahler, $kind]), 'one');
        self::assertSame([HinweisArt::FamilieZahlerAnzahl], self::arten($kind, [$zahler, $zahler2, $kind]), 'two');
        self::assertStringContainsString('2 Personen', Hinweise::fuer($kind, new HinweisKontext(self::tag(), [$zahler, $zahler2, $kind]))[0]->text);
    }

    // Zahler ohne Familienbeitrag

    public function testThePayerNeedsTheFamilyFee(): void
    {
        $kind = self::person(2, FamilienRolle::Kind);

        foreach ([self::NORMAL, null] as $andere) {
            $zahler = self::person(1, FamilienRolle::Zahler, ['beitragsartId' => $andere]);
            self::assertSame([HinweisArt::ZahlerOhneFamilienbeitrag], self::arten($zahler, [$zahler, $kind]));
        }
        self::assertSame([], self::arten(self::person(1, FamilienRolle::Zahler), [self::person(1, FamilienRolle::Zahler), $kind]));
    }

    // Familienmitglied mit eigenem Beitrag

    public function testParentsAndChildrenWithAFeeOfTheirOwnAreHinted(): void
    {
        $zahler = self::person(1, FamilienRolle::Zahler);

        foreach ([FamilienRolle::Elternteil, FamilienRolle::Kind] as $rolle) {
            self::assertSame([], self::arten(self::person(2, $rolle), [$zahler, self::person(2, $rolle)]));
            $eigener = self::person(2, $rolle, ['beitragsartId' => self::NORMAL]);
            self::assertSame([HinweisArt::FamilienmitgliedMitEigenemBeitrag], self::arten($eigener, [$zahler, $eigener]));
        }
    }

    // Andere Anschrift als die Familie

    public function testAnotherAddressThanThePayerIsHinted(): void
    {
        $zahler = self::person(1, FamilienRolle::Zahler);
        $kind = self::person(2, FamilienRolle::Kind);

        self::assertSame([], self::arten($kind, [$zahler, $kind]));
        self::assertSame([], self::arten($kind->mit(['strasse' => '  LINDENWEG 3 ']), [$zahler, $kind->mit(['strasse' => '  LINDENWEG 3 '])]), 'case and blanks do not count');

        $anders = $kind->mit(['strasse' => 'Bahnhofstraße 9']);
        self::assertSame([HinweisArt::AndereAnschrift], self::arten($anders, [$zahler, $anders]), 'street');
        $anders = $kind->mit(['plz' => '54321']);
        self::assertSame([HinweisArt::AndereAnschrift], self::arten($anders, [$zahler, $anders]), 'postcode');
        $anders = $kind->mit(['ort' => 'Anderswo']);
        self::assertSame([], self::arten($anders, [$zahler, $anders]), 'the town is not compared');
    }

    public function testThePayerHimselfAndAFamilyWithoutAPayerHaveNoOtherAddress(): void
    {
        $zahler = self::person(1, FamilienRolle::Zahler, ['strasse' => 'Bahnhofstraße 9']);
        $kind = self::person(2, FamilienRolle::Kind);

        self::assertSame([], self::arten($zahler, [$zahler, $kind]));
        self::assertNotContains(HinweisArt::AndereAnschrift, self::arten($kind, [$kind, self::person(3, FamilienRolle::Elternteil, ['strasse' => 'Bahnhofstraße 9'])]));
    }

    // Kind ist 18

    public function testAChildOf18IsHintedButAParentIsNot(): void
    {
        $zahler = self::person(1, FamilienRolle::Zahler);

        foreach ([17 => false, 18 => true, 30 => true] as $alter => $hinweis) {
            $kind = self::person(2, FamilienRolle::Kind, ['geburtsdatum' => self::alt($alter)]);
            self::assertSame($hinweis ? [HinweisArt::KindIst18] : [], self::arten($kind, [$zahler, $kind]), "age $alter");
        }
        $eltern = self::person(2, FamilienRolle::Elternteil, ['geburtsdatum' => self::alt(18)]);
        self::assertSame([], self::arten($eltern, [$zahler, $eltern]), 'only the role Kind');
    }

    // Kein Beitrag

    public function testNoFeeAndNoFamilyIsHintedUnlessFormer(): void
    {
        $ohne = self::mitglied(['beitragsartId' => null]);

        self::assertSame([HinweisArt::KeinBeitrag], self::arten($ohne));
        self::assertSame([], self::arten($ohne->mit(['austrittsdatum' => self::tag('2026-01-01')])), 'former');
        self::assertSame([], self::arten($ohne->mit(['austrittsdatum' => self::tag()])), 'leaving today counts as former (07 section 1)');
        self::assertSame([HinweisArt::KeinBeitrag], self::arten($ohne->mit(['austrittsdatum' => self::tag('2026-06-16')])), 'not yet former');
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::NORMAL])), 'with a fee');

        $kind = self::person(2, FamilienRolle::Kind);
        $zahler = self::person(1, FamilienRolle::Zahler);
        self::assertSame([], self::arten($kind, [$zahler, $kind]), 'a family counts as a way to pay');
    }

    // Lastschrift ohne gültige IBAN

    public function testDirectDebitNeedsAValidIban(): void
    {
        $m = self::mitglied(['zahlart' => Zahlart::Lastschrift]);

        self::assertSame([], self::arten($m));
        self::assertSame([HinweisArt::LastschriftOhneIban], self::arten($m->mit(['iban' => ''])), 'missing');
        self::assertSame([HinweisArt::LastschriftOhneIban], self::arten($m->mit(['iban' => 'DE00123456789012345678'])), 'wrong check digits');
        self::assertSame([HinweisArt::LastschriftOhneIban], self::arten($m->mit(['iban' => 'DE89'])), 'too short');
        self::assertSame([], self::arten($m->mit(['iban' => 'de89 3704 0044 0532 0130 00'])), 'spaces and case are fine');
        self::assertSame([], self::arten($m->mit(['zahlart' => Zahlart::Selbst, 'iban' => ''])), 'pays by themselves');
    }

    public function testTheTextSaysMissingOrWrong(): void
    {
        $m = self::mitglied();
        $kontext = new HinweisKontext(self::tag(), [], [], self::beitragsarten());

        self::assertStringContainsString('keine IBAN', Hinweise::fuer($m->mit(['iban' => '']), $kontext)[0]->text);
        self::assertStringContainsString('ungültig', Hinweise::fuer($m->mit(['iban' => 'DE00123456789012345678']), $kontext)[0]->text);
    }

    public function testAFamilyMemberPaidByThePayerNeedsNoIban(): void
    {
        $zahler = self::person(1, FamilienRolle::Zahler);
        $kind = self::person(2, FamilienRolle::Kind, ['iban' => '']);

        self::assertSame([], self::arten($kind, [$zahler, $kind]));
        $eigener = self::person(2, FamilienRolle::Kind, ['iban' => '', 'beitragsartId' => self::NORMAL]);
        self::assertContains(HinweisArt::LastschriftOhneIban, self::arten($eigener, [$zahler, $eigener]), 'with a fee of their own they pay');
        $falsch = self::person(2, FamilienRolle::Kind, ['iban' => 'DE00123456789012345678']);
        self::assertContains(HinweisArt::LastschriftOhneIban, self::arten($falsch, [$zahler, $falsch]), 'a wrong IBAN is wrong for everybody');
    }

    // Beitragsfrei ohne Grund

    public function testFeeFreeNeedsAReasonInTheNote(): void
    {
        $frei = self::mitglied(['beitragsartId' => self::FREI]);

        self::assertSame([HinweisArt::BeitragsfreiOhneGrund], self::arten($frei));
        self::assertSame([HinweisArt::BeitragsfreiOhneGrund], self::arten($frei->mit(['bemerkung' => "  \n "])), 'blanks are no reason');
        self::assertSame([], self::arten($frei->mit(['bemerkung' => 'Bildung und Teilhabe'])));
        self::assertSame([], self::arten(self::mitglied(['beitragsartId' => self::NORMAL, 'bemerkung' => ''])), 'only for the rule');
    }

    // Eintrittsdatum unbekannt

    public function testAMissingEntryDateIsHinted(): void
    {
        self::assertSame([HinweisArt::EintrittsdatumUnbekannt], self::arten(self::mitglied(['eintrittsdatum' => null])));
        self::assertSame([], self::arten(self::mitglied()));
    }

    // Pflichtangaben für BLSV

    public function testGenderAndBirthDateAreNeededForTheBlsvReport(): void
    {
        self::assertSame([HinweisArt::BlsvAngabenFehlen], self::arten(self::mitglied(['geschlecht' => null])));
        self::assertSame([HinweisArt::BlsvAngabenFehlen], self::arten(self::mitglied(['geburtsdatum' => null])));
        self::assertSame([HinweisArt::BlsvAngabenFehlen], self::arten(self::mitglied(['geschlecht' => null, 'geburtsdatum' => null])), 'one hint for both');
        self::assertSame([], self::arten(self::mitglied(['geschlecht' => Geschlecht::D])));

        $beide = Hinweise::fuer(self::mitglied(['geschlecht' => null, 'geburtsdatum' => null]), new HinweisKontext(self::tag()));
        self::assertStringContainsString('Geschlecht und das Geburtsdatum', $beide[0]->text);
    }

    // The function itself

    public function testTheSameInputGivesTheSameHints(): void
    {
        $m = self::mitglied(['beitragsartId' => null, 'eintrittsdatum' => null]);
        $kontext = new HinweisKontext(self::tag(), [], [], self::beitragsarten());

        self::assertEquals(Hinweise::fuer($m, $kontext), Hinweise::fuer($m, $kontext));
    }

    public function testAnUnsavedMemberGetsHintsToo(): void
    {
        $neu = new Mitglied(1, 'Muster');

        self::assertSame(
            [HinweisArt::KeinBeitrag, HinweisArt::LastschriftOhneIban, HinweisArt::EintrittsdatumUnbekannt, HinweisArt::BlsvAngabenFehlen],
            self::arten($neu),
        );
    }

    public function testHintTextsHoldNoPersonalData(): void
    {
        $m = self::mitglied(['beitragsartId' => self::RENTNER, 'geburtsdatum' => self::alt(60), 'iban' => '', 'nachname' => 'Geheimname', 'vorname' => 'Unbekannt']);
        foreach (Hinweise::fuer($m, new HinweisKontext(self::tag(), [], [], self::beitragsarten())) as $hinweis) {
            self::assertStringNotContainsString('Geheimname', $hinweis->text);
            self::assertStringNotContainsString('Unbekannt', $hinweis->text);
        }
    }

    public function testEveryKindHasAName(): void
    {
        foreach (HinweisArt::cases() as $art) {
            self::assertNotSame('', $art->bezeichnung());
        }
        self::assertCount(18, HinweisArt::cases(), 'one per row of the table in 07 section 6');
    }

    /**
     * The map (issue #108): "not found" and "approximately found" are
     * hints that point to the tab "Daten"; found, set by hand and not
     * asked yet are none.
     */
    public function testTheMapHintsOnlyWhatTheSearchLeftToFix(): void
    {
        $mitKarte = static fn(?GeoStatus $status, ?Mitglied $m = null): array => array_map(
            static fn($h): HinweisArt => $h->art,
            Hinweise::fuer($m ?? self::mitglied(), new HinweisKontext(self::tag(), [], [], self::beitragsarten(), $status)),
        );

        self::assertSame([HinweisArt::KarteNichtGefunden], $mitKarte(GeoStatus::NichtGefunden));
        self::assertSame([HinweisArt::KarteUngefaehr], $mitKarte(GeoStatus::Ungefaehr));
        self::assertSame([], $mitKarte(GeoStatus::Gefunden));
        self::assertSame([], $mitKarte(GeoStatus::VonHand));
        self::assertSame([], $mitKarte(null), 'not asked yet');
        self::assertSame([], $mitKarte(GeoStatus::NichtGefunden, self::mitglied(['austrittsdatum' => new \DateTimeImmutable('2020-12-31')])), 'a former member is not on the map');

        $text = Hinweise::fuer(self::mitglied(), new HinweisKontext(self::tag(), [], [], self::beitragsarten(), GeoStatus::Ungefaehr))[0]->text;
        self::assertStringContainsString('Reiter „Daten“', $text);
        self::assertStringContainsString('Punkt von Hand', $text);
        self::assertSame('daten', HinweisArt::KarteUngefaehr->reiter());
        self::assertSame('daten', HinweisArt::KarteNichtGefunden->reiter());
        self::assertNull(HinweisArt::KeinBeitrag->reiter());
        self::assertSame('Anschrift auf der Karte nicht gefunden', HinweisArt::KarteNichtGefunden->bezeichnung());
        self::assertSame('Anschrift auf der Karte nur ungefähr gefunden', HinweisArt::KarteUngefaehr->bezeichnung());
    }
}
