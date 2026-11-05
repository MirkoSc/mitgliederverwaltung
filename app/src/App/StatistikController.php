<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Service\Beitraege\Jahresbeitraege;
use App\Service\Statistik\Beitragsstatistik;
use App\Service\Statistik\Mitgliederstatistik;
use App\Support\Datum;
use App\View\Area;
use App\View\Navigation;

/**
 * The page "Statistik" (docs/spec/09-listen-und-exporte.md section 7,
 * 06 section 2) with two tabs: "Mitglieder" (counts, every role,
 * listen.sehen) and "Beiträge" (money, admin only, beitraege.verwalten -
 * the former "Verteilung der Beiträge" of 08 section 2b). Figures only, no
 * names. Routes: app/routes/statistik.php.
 */
final readonly class StatistikController
{
    public const string PFAD_BEITRAEGE = Navigation::STATISTIK . '/beitraege';

    /** Chart.js and the script drawing from the tables - only on these pages. */
    private const array SKRIPTE = ['/js/vendor/chartjs/chart.umd.min.js', '/js/statistik.js'];

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d));
    }

    public static function beitragsAdresse(int $jahr): string
    {
        return self::PFAD_BEITRAEGE . '?' . http_build_query(['jahr' => (string) $jahr]);
    }

    public function mitglieder(Request $r): Response
    {
        $heute = self::heute();

        return Response::html($this->dienste->view->render('statistik/mitglieder', [
            'title' => 'Statistik',
            'zahlen' => Mitgliederstatistik::mit($this->dienste->pdo())->fuer($heute),
            ...$this->reiter('mitglieder', $heute),
        ], Area::App));
    }

    public function beitraege(Request $r): Response
    {
        $heute = self::heute();
        $jahr = self::jahr($r->feld('jahr'), $heute);

        return Response::html($this->dienste->view->render('statistik/beitraege', [
            'title' => 'Statistik der Beiträge ' . $jahr,
            'zahlen' => Beitragsstatistik::mit($this->dienste->pdo())->fuerJahr($jahr),
            'vorjahr' => $jahr > Jahresbeitraege::ERSTES_JAHR ? $jahr - 1 : null,
            'folgejahr' => $jahr < Jahresbeitraege::letztesJahr($heute) ? $jahr + 1 : null,
            'beitraegeAdresse' => BeitraegeController::seitenAdresse($jahr),
            ...$this->reiter('beitraege', $heute, $jahr),
        ], Area::App));
    }

    /** The old address /app/beitraege/verteilung (08 section 2b): moved here. */
    public function alteVerteilung(Request $r): Response
    {
        return Response::redirect(self::beitragsAdresse(self::jahr($r->feld('jahr'), self::heute())));
    }

    /**
     * The tabs; "Beiträge" only for whoever may open it.
     *
     * @return array<string, mixed>
     */
    private function reiter(string $aktiv, \DateTimeImmutable $heute, ?int $jahr = null): array
    {
        $reiter = ['mitglieder' => ['Mitglieder', Navigation::STATISTIK]];
        if ($this->dienste->view->konto()?->rolle->darf(Permission::BeitraegeVerwalten) === true) {
            $reiter['beitraege'] = ['Beiträge', self::beitragsAdresse($jahr ?? (int) $heute->format('Y'))];
        }

        return ['reiter' => $reiter, 'aktiverReiter' => $aktiv, 'scripts' => self::SKRIPTE];
    }

    /** A year with fees (or a projection); anything else is this year. */
    private static function jahr(string $eingabe, \DateTimeImmutable $heute): int
    {
        $jahr = preg_match('/^[0-9]{4}$/D', $eingabe) === 1 ? (int) $eingabe : null;

        return $jahr !== null && $jahr >= Jahresbeitraege::ERSTES_JAHR && $jahr <= Jahresbeitraege::letztesJahr($heute)
            ? $jahr
            : (int) $heute->format('Y');
    }

    private static function heute(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(Datum::ZEITZONE));
    }
}
