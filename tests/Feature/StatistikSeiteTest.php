<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Geschlecht;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\BeitragsartRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * The page "Statistik" (docs/spec/09-listen-und-exporte.md section 7)
 * through the whole application: every role sees the member figures, only
 * the admin the fee figures; the former "Verteilung der Beiträge" redirects
 * there. Every name is made up.
 */
final class StatistikSeiteTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function mitglied(int $nummer, string $nachname, string $art = 'Aktiv', ?Geschlecht $geschlecht = null, ?string $austritt = null): int
    {
        $artId = null;
        foreach (new BeitragsartRepository($this->dienste->pdo())->alle() as $kandidat) {
            if ($kandidat->name === $art) {
                $artId = $kandidat->id;
            }
        }

        return (int) new MitgliedRepository($this->dienste->pdo())->speichern(
            new Mitglied(
                $nummer,
                $nachname,
                vorname: 'Erika',
                geschlecht: $geschlecht,
                geburtsdatum: new \DateTimeImmutable('1990-04-01'),
                ort: 'Musterstadt',
                eintrittsdatum: new \DateTimeImmutable('2015-01-01'),
                austrittsdatum: $austritt === null ? null : new \DateTimeImmutable($austritt),
                beitragsartId: $artId,
            ),
            VersionsAnlass::angelegt(),
            null,
        )->mitglied->id;
    }

    public function testEveryRoleSeesTheMemberFiguresWithoutNames(): void
    {
        $this->mitglied(1, 'Musterfrau', geschlecht: Geschlecht::W);
        $this->mitglied(2, 'Beispiel', geschlecht: Geschlecht::M, austritt: '2020-06-30');

        foreach ([Rolle::Admin, Rolle::Vorstand, Rolle::Lesend] as $rolle) {
            $this->als($rolle);
            $seite = $this->get('/app/statistik');

            self::assertSame(200, $seite->status, $rolle->name);
            self::assertStringContainsString('<h1>Statistik</h1>', $seite->body);
            self::assertStringContainsString('Altersgruppen', $seite->body);
            self::assertStringContainsString('Eintritte und Austritte', $seite->body);
            self::assertStringContainsString('data-diagramm="gestapelt"', $seite->body, 'the table carries the chart');
            self::assertStringContainsString('/js/vendor/chartjs/chart.umd.min.js', $seite->body);
            self::assertStringContainsString('/js/statistik.js', $seite->body);
            self::assertStringNotContainsString('Musterfrau', $seite->body, 'figures only, no names');
            self::assertStringNotContainsString('Beispiel,', $seite->body);
            self::assertMatchesRegularExpression('#<dd>1</dd>\s*</div>#', $seite->body);
            self::assertSame($rolle === Rolle::Admin, str_contains($seite->body, '/app/statistik/beitraege?jahr='), 'the tab "Beiträge" is the admin\'s');
            self::assertStringContainsString('href="/app/statistik" aria-current="page"', $seite->body, 'main entry marked');
        }
    }

    public function testOnlyTheAdminSeesTheFeeFigures(): void
    {
        $this->mitglied(1, 'Musterfrau');
        foreach ([Rolle::Vorstand, Rolle::Lesend] as $rolle) {
            $this->als($rolle);
            self::assertSame(403, $this->get('/app/statistik/beitraege', ['jahr' => '2026'])->status, $rolle->name);
            self::assertSame(403, $this->get('/app/beitraege/verteilung', ['jahr' => '2026'])->status, $rolle->name);
        }
    }

    public function testTheFeeFiguresAreProjectedThenTakenFromTheCreatedFees(): void
    {
        $this->mitglied(1, 'Musterfrau', geschlecht: Geschlecht::W);
        $this->mitglied(2, 'Beispiel', 'Passiv');
        $this->als(Rolle::Admin);

        self::assertStringContainsString('/app/statistik/beitraege?jahr=2026', $this->get('/app/beitraege', ['jahr' => '2026'])->body, '"Verteilung 2026 ansehen" leads here');

        $seite = $this->get('/app/statistik/beitraege', ['jahr' => '2026']);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Statistik der Beiträge 2026', $seite->body);
        self::assertStringContainsString('hochgerechnet', $seite->body);
        self::assertStringContainsString('135,00', $seite->body);
        foreach (['Nach Beitragsart', 'Nach Abteilung', 'ohne Abteilung', 'Nach Geschlecht', 'Nach Altersgruppe', 'Nach Wohnort', 'Aktiv und passiv', 'Familien und Einzelmitglieder', 'Nach Zahlart'] as $text) {
            self::assertStringContainsString($text, $seite->body);
        }
        self::assertStringContainsString('data-einheit="cent"', $seite->body);
        self::assertStringContainsString('data-wert="8500"', $seite->body, 'raw cent for the chart');
        self::assertStringNotContainsString('Musterfrau', $seite->body, 'figures only, no names');
        self::assertStringContainsString('aria-current="page">Beiträge</a>', $seite->body);

        $this->post('/app/beitraege/erzeugen', ['jahr' => '2026']);
        $beitraege = new JahresbeitragRepository($this->dienste->pdo());
        $beitraege->speichern($beitraege->imJahr(2026)[0]->mit(['betragCent' => 1000]));

        $seite = $this->get('/app/statistik/beitraege', ['jahr' => '2026'])->body;
        self::assertStringNotContainsString('hochgerechnet', $seite);
        self::assertStringContainsString('erzeugten Jahresbeiträgen 2026', $seite);
        self::assertStringContainsString('60,00', $seite, 'the changed amount counts');
    }

    public function testTheOldAddressRedirects(): void
    {
        $this->als(Rolle::Admin);

        self::assertWeiterleitungNach('/app/statistik/beitraege?jahr=2025', $this->get('/app/beitraege/verteilung', ['jahr' => '2025']));
        $jahr = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'))->format('Y');
        self::assertWeiterleitungNach('/app/statistik/beitraege?jahr=' . $jahr, $this->get('/app/beitraege/verteilung', ['jahr' => 'abc']));
    }

    public function testTheBarrierefreiModusKeepsTheTables(): void
    {
        $this->mitglied(1, 'Musterfrau');
        $this->als(Rolle::Vorstand, barrierefrei: true);

        $seite = $this->get('/app/statistik')->body;
        self::assertStringContainsString('modus-barrierefrei', $seite);
        self::assertStringContainsString('<details class="statistik-tabelle" open>', $seite, 'open without a script; statistik.js leaves it so in this mode');
    }
}
