<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Http\Request;
use App\Http\Response;
use App\Listen\EhrungenListe;
use App\Repository\EhrungRepository;
use App\Support\Datum;
use App\View\FlashArt;

/**
 * The buttons "geehrt" and "Zurücknehmen" of the honours list
 * (docs/spec/09-listen-und-exporte.md section 2). Both roles. Answers with a
 * redirect to the list, with the same year and filter.
 */
final readonly class EhrungenController
{
    public function __construct(private Dienste $dienste, private EhrungRepository $ehrungen)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d, new EhrungRepository($d->pdo())));
    }

    public function vermerken(Request $r): Response
    {
        $mitgliedId = (int) $r->feld('mitglied');
        $stufe = (int) $r->feld('stufe');
        $jahr = (int) $r->feld('jahr');

        if ($mitgliedId < 1 || $stufe < 1) {
            $this->dienste->session->flash('Diese Angabe war unvollständig.', FlashArt::Warnung);
        } elseif ($r->feld('aktion') === 'zurueck') {
            $this->ehrungen->zuruecknehmen($mitgliedId, $stufe);
            $this->dienste->session->flash('Der Vermerk „geehrt“ ist zurückgenommen.');
        } elseif ($this->ehrungen->vermerken($mitgliedId, $stufe, new \DateTimeImmutable('today', new \DateTimeZone(Datum::ZEITZONE)), $this->dienste->session->userId())) {
            $this->dienste->session->flash('Als geehrt vermerkt.');
        } else {
            $this->dienste->session->flash('Dieses Mitglied ist für diese Stufe schon als geehrt vermerkt.', FlashArt::Warnung);
        }

        $abfrage = [];
        if ($jahr >= 1900 && $jahr <= 2200) {
            $abfrage['jahr'] = (string) $jahr;
        }
        if ($r->feld('nur_offene') === '1') {
            $abfrage['nur_offene'] = '1';
        }

        return Response::redirect('/app/listen/ehrungen' . ($abfrage === [] ? '' : '?' . http_build_query($abfrage)));
    }
}
