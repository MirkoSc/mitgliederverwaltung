<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\MitgliederFilter;
use App\Domain\MitgliederSortierung;
use App\Domain\MitgliederStatus;
use PHPUnit\Framework\TestCase;

/** The state of the member list as a value (docs/spec/06-oberflaeche.md sections 2 and 3). */
final class MitgliederFilterTest extends TestCase
{
    public function testNothingGivenIsTheDefaultList(): void
    {
        $filter = MitgliederFilter::aus([]);

        self::assertSame('', $filter->text);
        self::assertNull($filter->abteilungId);
        self::assertSame(MitgliederStatus::Alle, $filter->status);
        self::assertNull($filter->beitragsartId);
        self::assertFalse($filter->mitEhemaligen, 'former members are hidden by default');
        self::assertSame(MitgliederSortierung::Name, $filter->sortierung);
        self::assertFalse($filter->absteigend);
        self::assertSame(1, $filter->seite);
        self::assertFalse($filter->istGefiltert());
        self::assertSame([], $filter->parameter());
    }

    public function testEverythingIsReadFromTheForm(): void
    {
        $filter = MitgliederFilter::aus([
            'text' => '  Erika  Muster ',
            'abteilung' => '3',
            'status' => 'passiv',
            'beitragsart' => '7',
            'ehemalige' => '1',
            'sortierung' => 'ort-ab',
            'seite' => '4',
        ]);

        self::assertSame('Erika  Muster', $filter->text);
        self::assertSame(3, $filter->abteilungId);
        self::assertSame(MitgliederStatus::Passiv, $filter->status);
        self::assertSame(7, $filter->beitragsartId);
        self::assertTrue($filter->mitEhemaligen);
        self::assertSame(MitgliederSortierung::Ort, $filter->sortierung);
        self::assertTrue($filter->absteigend);
        self::assertSame(4, $filter->seite);
        self::assertTrue($filter->istGefiltert());
    }

    /** An unticked box sends the hidden 0 - that is "unticked", not "ticked". */
    public function testTheUntickedBoxIsNotTicked(): void
    {
        self::assertFalse(MitgliederFilter::aus(['ehemalige' => '0'])->mitEhemaligen);
    }

    /** A mistyped URL still shows a list. */
    public function testGarbageFallsBackToTheDefaults(): void
    {
        $filter = MitgliederFilter::aus([
            'text' => ['a'],
            'abteilung' => 'x; DROP TABLE mitglied',
            'status' => 'beides',
            'beitragsart' => '0',
            'sortierung' => 'passwort',
            'seite' => '-3',
        ]);

        self::assertEquals(MitgliederFilter::aus([]), $filter);
    }

    public function testTheWordsAreSeparatedByBlanksAndCommas(): void
    {
        self::assertSame(['Muster', 'Erika'], MitgliederFilter::aus(['text' => ' Muster,  Erika '])->woerter());
        self::assertSame([], MitgliederFilter::aus(['text' => ' , '])->woerter());
    }

    /** CLAUDE.md section 4: no member data in a URL - the text is not part of the parameters. */
    public function testTheSearchTextNeverBecomesAUrlParameter(): void
    {
        $filter = MitgliederFilter::aus([
            'text' => 'Mustermann',
            'abteilung' => '2',
            'status' => 'aktiv',
            'beitragsart' => '5',
            'ehemalige' => '1',
            'sortierung' => 'nummer-ab',
            'seite' => '3',
        ]);

        self::assertSame(
            ['abteilung' => '2', 'status' => 'aktiv', 'beitragsart' => '5', 'ehemalige' => '1', 'sortierung' => 'nummer-ab', 'seite' => '3'],
            $filter->parameter(),
        );
        self::assertStringNotContainsString('Mustermann', http_build_query($filter->parameter()));
    }

    public function testParametersRebuildTheSameFilterWithoutTheText(): void
    {
        $filter = MitgliederFilter::aus(['abteilung' => '2', 'status' => 'passiv', 'ehemalige' => '1', 'sortierung' => 'ort-ab', 'seite' => '2']);

        self::assertEquals($filter, MitgliederFilter::aus($filter->parameter()));
    }

    public function testAClickOnTheHeaderSortsAscendingFirstThenDescending(): void
    {
        $name = MitgliederFilter::aus([]);
        self::assertSame('name-ab', $name->sortierungNach(MitgliederSortierung::Name), 'already by name: reverse');
        self::assertSame('nummer', $name->sortierungNach(MitgliederSortierung::Nummer), 'another column starts ascending');

        $absteigend = MitgliederFilter::aus(['sortierung' => 'name-ab']);
        self::assertSame('name', $absteigend->sortierungNach(MitgliederSortierung::Name), 'descending: back to ascending');
    }

    public function testPageSizeIsOneOfTheOfferedValues(): void
    {
        self::assertSame(50, MitgliederFilter::aus([])->anzahl);
        self::assertSame(100, MitgliederFilter::aus(['anzahl' => '100'])->anzahl);
        self::assertSame(200, MitgliederFilter::aus(['anzahl' => '200'])->anzahl);
        self::assertSame(0, MitgliederFilter::aus(['anzahl' => 'alle'])->anzahl, '0 = all');
        self::assertSame(50, MitgliederFilter::aus(['anzahl' => '75'])->anzahl, 'not offered: default');
        self::assertSame(50, MitgliederFilter::aus(['anzahl' => '0'])->anzahl, '"all" is the word, not 0');
        self::assertSame(50, MitgliederFilter::aus(['anzahl' => 'x'])->anzahl);

        self::assertArrayNotHasKey('anzahl', MitgliederFilter::aus([])->parameter(), 'the default stays out of the URL');
        self::assertSame('alle', MitgliederFilter::aus(['anzahl' => 'alle'])->parameter()['anzahl']);
        $filter = MitgliederFilter::aus(['anzahl' => '200', 'seite' => '2']);
        self::assertEquals($filter, MitgliederFilter::aus($filter->parameter()));
    }
}
