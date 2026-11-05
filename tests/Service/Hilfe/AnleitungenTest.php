<?php

declare(strict_types=1);

namespace App\Tests\Service\Hilfe;

use App\Domain\Permission;
use App\Domain\Rolle;
use App\Service\Hilfe\Anleitung;
use App\Service\Hilfe\Anleitungen;
use App\Service\Hilfe\UngueltigeAnleitung;
use App\Tests\Support\TestBilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Loading the guides of the help (docs/spec/12-hilfe.md section 2 and the
 * "Pflicht-Tests" of section 7): order, slug, every rule as an error of its
 * own, the role filter - and every real file under app/data/hilfe/.
 */
final class AnleitungenTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/hilfe';

    private ?string $temp = null;

    protected function tearDown(): void
    {
        if ($this->temp !== null) {
            TestBilder::loeschen($this->temp);
        }
    }

    private static function gueltige(string $bilder = '/nirgends'): Anleitungen
    {
        return new Anleitungen(self::FIXTURES . '/gueltig', $bilder, '/img/hilfe', '1.2.3');
    }

    /**
     * @param list<Anleitung> $anleitungen
     * @return list<string>
     */
    private static function slugs(array $anleitungen): array
    {
        return array_map(static fn(Anleitung $a): string => $a->slug, $anleitungen);
    }

    private function tempVerzeichnis(): string
    {
        $this->temp = sys_get_temp_dir() . '/mv-hilfe-' . bin2hex(random_bytes(4));
        mkdir($this->temp, 0o700, true);

        return $this->temp;
    }

    public function testGuidesComeInTheOrderOfTheirFileNamesWithTheSlugFromTheName(): void
    {
        $alle = self::gueltige()->alle();

        self::assertSame(['erste-anleitung', 'bearbeiten', 'sehen', 'nur-admin'], self::slugs($alle));
        self::assertSame('Erste Anleitung', $alle[0]->titel);
        self::assertSame('Für jeden angemeldeten Benutzer.', $alle[0]->kurz);
        self::assertNull($alle[0]->recht);
        self::assertSame(Permission::MitgliederBearbeiten, $alle[1]->recht);
    }

    public function testStepsAreNumberedFromOneWithOptionalPictureAndTip(): void
    {
        $schritte = self::gueltige()->alle()[0]->schritte;

        self::assertCount(3, $schritte);
        self::assertSame([1, 2, 3], array_map(static fn($s): int => $s->nummer, $schritte));
        self::assertSame('Die Seite Mitglieder mit dem markierten Knopf „Neues Mitglied".', $schritte[0]->bild);
        self::assertNull($schritte[0]->tipp);
        self::assertNull($schritte[1]->bild);
        self::assertSame('**Mitgliedsnummer** und Nachname genügen. <b>nicht fett</b>', $schritte[1]->tipp);
        self::assertSame('03', $schritte[2]->bildNummer());
        self::assertSame([1, 3], self::gueltige()->alle()[0]->schritteMitBild());
    }

    /** @return iterable<string, array{Rolle, list<string>}> */
    public static function rollen(): iterable
    {
        yield 'Admin' => [Rolle::Admin, ['erste-anleitung', 'bearbeiten', 'sehen', 'nur-admin']];
        yield 'Vorstand' => [Rolle::Vorstand, ['erste-anleitung', 'bearbeiten', 'sehen']];
        yield 'Nur lesen' => [Rolle::Lesend, ['erste-anleitung', 'sehen']];
    }

    /**
     * @param list<string> $erwartet
     */
    #[DataProvider('rollen')]
    public function testEachRoleGetsOnlyTheGuidesItMayCarryOut(Rolle $rolle, array $erwartet): void
    {
        $hilfe = self::gueltige();

        self::assertSame($erwartet, self::slugs($hilfe->fuer($rolle)));
        foreach ($hilfe->alle() as $anleitung) {
            $gefunden = $hilfe->finde($anleitung->slug, $rolle);
            self::assertSame(in_array($anleitung->slug, $erwartet, true), $gefunden !== null, $anleitung->slug);
        }
        self::assertNull($hilfe->finde('gibt-es-nicht', $rolle));
    }

    public function testTheNextGuideSkipsWhatTheRoleMayNotCarryOut(): void
    {
        $hilfe = self::gueltige();
        $erste = $hilfe->alle()[0];
        $sehen = $hilfe->alle()[2];

        self::assertSame('bearbeiten', $hilfe->naechste($erste, Rolle::Vorstand)?->slug);
        self::assertSame('sehen', $hilfe->naechste($erste, Rolle::Lesend)?->slug);
        self::assertSame('nur-admin', $hilfe->naechste($sehen, Rolle::Admin)?->slug);
        self::assertNull($hilfe->naechste($sehen, Rolle::Vorstand), 'the last allowed guide has no next one');
    }

    /** @return iterable<string, array{string, string}> */
    public static function fehler(): iterable
    {
        yield 'unbekannter Schlüssel' => ['01-unbekannter-schluessel.php', 'unknown key "bilder"'];
        yield 'fehlender Titel' => ['01-ohne-titel.php', 'the key "titel" is missing'];
        yield 'leerer Titel' => ['01-leerer-titel.php', '"titel" must be a non-empty text'];
        yield 'falsches Recht' => ['01-falsches-recht.php', '"recht" must be null or an App\Domain\Permission'];
        yield 'keine Schritte' => ['01-keine-schritte.php', '"schritte" must be a non-empty list'];
        yield 'zu viele Schritte' => ['01-zu-viele-schritte.php', 'more than 12 steps'];
        yield 'leerer Text' => ['01-leerer-text.php', '"step 2 text" must be a non-empty text'];
        yield 'fehlender Text' => ['01-ohne-text.php', 'step 1: the key "text" is missing'];
        yield 'leeres Bild' => ['01-leeres-bild.php', '"step 1 bild" must be a non-empty text'];
        yield 'unbekannter Schlüssel im Schritt' => ['01-unbekannter-schritt-schluessel.php', 'step 1: unknown key "video"'];
        yield 'falscher Dateiname' => ['Falscher_Name.php', 'the file name must be NN-slug.php'];
        yield 'eine Ziffer im Dateinamen' => ['1-eine-ziffer.php', 'the file name must be NN-slug.php'];
    }

    #[DataProvider('fehler')]
    public function testEveryRuleIsAnErrorOfItsOwn(string $datei, string $grund): void
    {
        $this->expectException(UngueltigeAnleitung::class);
        $this->expectExceptionMessage($datei . ': ' . $grund);

        Anleitungen::laden(self::FIXTURES . '/fehler/' . $datei);
    }

    /** One broken file breaks the help - loudly, not by leaving a guide out. */
    public function testOneBrokenFileFailsTheWholeDirectory(): void
    {
        $this->expectException(UngueltigeAnleitung::class);

        new Anleitungen(self::FIXTURES . '/fehler', '/nirgends')->alle();
    }

    public function testTwoFilesWithTheSameSlugAreAnError(): void
    {
        $verzeichnis = $this->tempVerzeichnis();
        copy(self::FIXTURES . '/gueltig/03-sehen.php', $verzeichnis . '/03-sehen.php');
        copy(self::FIXTURES . '/gueltig/03-sehen.php', $verzeichnis . '/07-sehen.php');

        $this->expectException(UngueltigeAnleitung::class);
        $this->expectExceptionMessage('07-sehen.php: the slug "sehen" is already taken');

        new Anleitungen($verzeichnis, '/nirgends')->alle();
    }

    public function testWithoutADirectoryThereAreNoGuides(): void
    {
        self::assertSame([], new Anleitungen('/gibt/es/nicht', '/nirgends')->alle());
    }

    /** 12 section 7: every real guide is valid - also while there is none yet. */
    public function testEveryRealGuideIsValid(): void
    {
        $verzeichnis = dirname(__DIR__, 3) . '/app/data/hilfe';
        $hilfe = new Anleitungen($verzeichnis, dirname(__DIR__, 3) . '/public/img/hilfe');

        $alle = $hilfe->alle();
        self::assertCount(count(glob($verzeichnis . '/*.php') ?: []), $alle);
        foreach ($alle as $anleitung) {
            self::assertMatchesRegularExpression('/^' . Anleitungen::SLUG . '$/', $anleitung->slug);
        }
    }

    /**
     * The picture in the variant of the account's mode, with its size and
     * the version for the cache - and none when the file is missing.
     */
    public function testThePictureFollowsTheModeAndNeedsTheFile(): void
    {
        $bilder = $this->tempVerzeichnis();
        mkdir($bilder . '/erste-anleitung');
        rename(TestBilder::png($bilder, 400, 250), $bilder . '/erste-anleitung/01-normal.png');
        rename(TestBilder::png($bilder, 600, 375), $bilder . '/erste-anleitung/01-barrierefrei.png');
        $hilfe = self::gueltige($bilder);
        $anleitung = $hilfe->alle()[0];
        [$eins, $zwei, $drei] = $anleitung->schritte;

        $normal = $hilfe->bild($anleitung, $eins, false);
        self::assertNotNull($normal);
        self::assertSame('/img/hilfe/erste-anleitung/01-normal.png?v=1.2.3', $normal->url);
        self::assertSame([400, 250], [$normal->breite, $normal->hoehe]);
        self::assertSame($eins->bild, $normal->alt);

        $barrierefrei = $hilfe->bild($anleitung, $eins, true);
        self::assertSame('/img/hilfe/erste-anleitung/01-barrierefrei.png?v=1.2.3', $barrierefrei?->url);
        self::assertSame(600, $barrierefrei->breite);

        self::assertNull($hilfe->bild($anleitung, $zwei, false), 'a step without "bild" has no picture');
        self::assertNull($hilfe->bild($anleitung, $drei, false), 'a missing file means no picture');
    }

    /** What tools/hilfe-anleitungen.php prints for the screenshot script (#115). */
    public function testTheProfilesForTheScreenshotScript(): void
    {
        $steckbriefe = self::gueltige()->steckbriefe();

        self::assertSame(
            ['slug' => 'erste-anleitung', 'titel' => 'Erste Anleitung', 'recht' => null, 'bilder' => [1, 3]],
            $steckbriefe[0],
        );
        self::assertSame(
            ['slug' => 'bearbeiten', 'titel' => 'Zum Bearbeiten', 'recht' => 'mitglieder.bearbeiten', 'bilder' => []],
            $steckbriefe[1],
        );
        self::assertCount(4, $steckbriefe);
    }
}
