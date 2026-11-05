<?php

declare(strict_types=1);

namespace App\Tests\Service\Hilfe;

use App\Domain\Rolle;
use App\Service\Hilfe\Anleitung;
use App\Service\Hilfe\Anleitungen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The real guides under app/data/hilfe/ with their pictures under
 * public/img/hilfe/ - the "Pflicht-Tests" of the closing issue (#119) in
 * docs/spec/12-hilfe.md section 7: both pictures for every step with
 * "bild", no orphaned picture, real PNG files within the limits of
 * section 6, and the role filter with the guides of section 8.
 */
final class EchteAnleitungenTest extends TestCase
{
    private const string WURZEL = __DIR__ . '/../../..';

    private const array MODI = ['normal', 'barrierefrei'];

    /** 12 section 6: every picture, and all together (they go into each release ZIP). */
    private const int BILD_MAX_BYTES = 250 * 1024;

    private const int GESAMT_MAX_BYTES = 10 * 1024 * 1024;

    /** 12 section 6: window 1280 × 800, deviceScaleFactor 1. */
    private const array GROESSE = [1280, 800];

    private static function bildVerzeichnis(): string
    {
        return self::WURZEL . '/public/img/hilfe';
    }

    private static function hilfe(): Anleitungen
    {
        return new Anleitungen(self::WURZEL . '/app/data/hilfe', self::bildVerzeichnis());
    }

    /**
     * Every picture the guides ask for, relative to public/img/hilfe/.
     *
     * @return list<string>
     */
    private static function erwarteteBilder(): array
    {
        $bilder = [];
        foreach (self::hilfe()->alle() as $anleitung) {
            foreach ($anleitung->schritte as $schritt) {
                if ($schritt->bild === null) {
                    continue;
                }
                foreach (self::MODI as $modus) {
                    $bilder[] = sprintf('%s/%s-%s.png', $anleitung->slug, $schritt->bildNummer(), $modus);
                }
            }
        }
        sort($bilder);

        return $bilder;
    }

    /**
     * Every file and directory below public/img/hilfe/, relative to it.
     *
     * @return array{dateien: list<string>, verzeichnisse: list<string>}
     */
    private static function vorhanden(): array
    {
        $dateien = [];
        $verzeichnisse = [];
        $basis = self::bildVerzeichnis();
        if (!is_dir($basis)) {
            return ['dateien' => [], 'verzeichnisse' => []];
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $eintrag) {
            \assert($eintrag instanceof \SplFileInfo);
            $relativ = substr($eintrag->getPathname(), strlen($basis) + 1);
            if ($eintrag->isDir()) {
                $verzeichnisse[] = $relativ;
            } else {
                $dateien[] = $relativ;
            }
        }
        sort($dateien);
        sort($verzeichnisse);

        return ['dateien' => $dateien, 'verzeichnisse' => $verzeichnisse];
    }

    public function testEveryStepWithAPictureHasBothPictures(): void
    {
        $erwartet = self::erwarteteBilder();
        self::assertNotEmpty($erwartet, 'the guides of section 8 have pictures');

        $fehlend = array_values(array_diff($erwartet, self::vorhanden()['dateien']));
        self::assertSame([], $fehlend, 'missing pictures - tools/hilfe-screenshots/start.sh creates them');
    }

    public function testThereIsNoOrphanedPictureOrFile(): void
    {
        $vorhanden = self::vorhanden();

        $verwaist = array_values(array_diff($vorhanden['dateien'], self::erwarteteBilder()));
        self::assertSame([], $verwaist, 'files under public/img/hilfe/ no step asks for');

        $slugs = array_map(static fn(Anleitung $a): string => $a->slug, self::hilfe()->alle());
        $leer = array_values(array_diff($vorhanden['verzeichnisse'], $slugs));
        self::assertSame([], $leer, 'directories under public/img/hilfe/ without a guide');
    }

    public function testEveryPictureIsARealPngOfTheRecordedSizeWithinTheLimit(): void
    {
        foreach (self::vorhanden()['dateien'] as $relativ) {
            $pfad = self::bildVerzeichnis() . '/' . $relativ;
            $inhalt = (string) file_get_contents($pfad);

            self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $inhalt, $relativ . ' is no PNG');
            $info = getimagesizefromstring($inhalt);
            self::assertIsArray($info, $relativ);
            self::assertSame(IMAGETYPE_PNG, $info[2], $relativ);
            self::assertSame(self::GROESSE, [$info[0], $info[1]], $relativ . ': window 1280 × 800');
            self::assertLessThanOrEqual(self::BILD_MAX_BYTES, strlen($inhalt), $relativ . ' is larger than 250 KB');
        }
    }

    public function testAllPicturesTogetherStayWithinTheLimit(): void
    {
        $gesamt = 0;
        foreach (self::vorhanden()['dateien'] as $relativ) {
            $gesamt += (int) filesize(self::bildVerzeichnis() . '/' . $relativ);
        }

        self::assertLessThanOrEqual(self::GESAMT_MAX_BYTES, $gesamt, 'all pictures together are larger than 10 MB');
    }

    /** The page finds the picture of every mode (12 section 3). */
    public function testThePagePicksThePictureOfTheMode(): void
    {
        $hilfe = self::hilfe();
        foreach ($hilfe->alle() as $anleitung) {
            foreach ($anleitung->schritte as $schritt) {
                if ($schritt->bild === null) {
                    continue;
                }
                foreach (['normal' => false, 'barrierefrei' => true] as $modus => $barrierefrei) {
                    $bild = $hilfe->bild($anleitung, $schritt, $barrierefrei);
                    self::assertNotNull($bild, $anleitung->slug . ' step ' . $schritt->nummer . ' ' . $modus);
                    self::assertStringContainsString('/' . $schritt->bildNummer() . '-' . $modus . '.png', $bild->url);
                    self::assertSame(self::GROESSE, [$bild->breite, $bild->hoehe]);
                }
            }
        }
    }

    /** @return iterable<string, array{Rolle, list<string>}> */
    public static function rollen(): iterable
    {
        $alle = [
            'anmelden',
            'mitglied-suchen',
            'mitglied-anlegen',
            'anschrift-aendern',
            'beitragsart-aendern',
            'abteilung-zuordnen',
            'austritt-eintragen',
            'barrierefrei-modus',
            'geburtstagsliste',
            'mitglied-loeschen',
            'statistik',
            'app-installieren',
        ];

        yield 'Admin' => [Rolle::Admin, $alle];
        // Deleting a member is the admin's alone (02 section 6).
        yield 'Vorstand' => [Rolle::Vorstand, array_values(array_diff($alle, ['mitglied-loeschen']))];
        yield 'Nur lesen' => [Rolle::Lesend, ['anmelden', 'mitglied-suchen', 'barrierefrei-modus', 'geburtstagsliste', 'statistik', 'app-installieren']];
    }

    /**
     * 12 section 7: Admin sees all guides, Vorstand all but the deleting of
     * a member, Nur lesen exactly the six it can carry out.
     *
     * @param list<string> $erwartet
     */
    #[DataProvider('rollen')]
    public function testEachRoleSeesTheRealGuidesItMayCarryOut(Rolle $rolle, array $erwartet): void
    {
        $slugs = array_map(static fn(Anleitung $a): string => $a->slug, self::hilfe()->fuer($rolle));

        self::assertSame($erwartet, $slugs);
    }
}
