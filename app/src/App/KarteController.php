<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\MitgliederStatus;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AbteilungRepository;
use App\Repository\GeoAnschriftRepository;
use App\Repository\KartenRepository;
use App\Service\Karte\KachelProxy;
use App\Service\Karte\KartenAnsicht;
use App\Service\Karte\Koordinatenermittlung;
use App\Support\Datum;
use App\View\Area;
use App\View\FlashArt;
use App\View\Navigation;

/**
 * The page "Karte" (issue #91): the members' addresses on an OpenStreetMap
 * map, with a text list of the same addresses below it - which is all the
 * Barrierefrei-Modus needs. Also the tile proxy the map loads its images
 * from, the step of the admin's button "Jetzt Koordinaten ermitteln", and
 * "Alle neu ermitteln" (issue #108), which forgets the automatic results
 * before that chain runs.
 */
final readonly class KarteController
{
    /** Tile route; Leaflet fills in {z}/{x}/{y}. */
    public const string PFAD_KACHEL = Navigation::KARTE . '/kachel';

    public const string PFAD_ERMITTELN = Navigation::KARTE . '/ermitteln';

    public const string PFAD_NEU_ERMITTELN = Navigation::KARTE . '/neu-ermitteln';

    /** Addresses per step of the admin's chain - a step answers within seconds. */
    public const int SCHRITT_ANZAHL = 5;

    public const float SCHRITT_BUDGET_S = 8.0;

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d));
    }

    public function seite(Request $request): Response
    {
        $pdo = $this->dienste->pdo();
        $heute = $this->jetzt();
        $abteilungen = new AbteilungRepository($pdo)->alle();

        $abteilungId = self::id($request->query['abteilung'] ?? null);
        if ($abteilungId !== null && !in_array($abteilungId, array_map(static fn($a): ?int => $a->id, $abteilungen), true)) {
            $abteilungId = null;
        }
        $status = MitgliederStatus::tryFrom(is_string($request->query['status'] ?? null) ? $request->query['status'] : '') ?? MitgliederStatus::Alle;

        $ansicht = KartenAnsicht::aus(
            new KartenRepository($pdo)->mitglieder($heute, $abteilungId, $status),
            new GeoAnschriftRepository($pdo)->alle(),
        );
        $konto = $this->dienste->view->konto();

        return Response::html($this->dienste->view->render('karte/seite', [
            'title' => 'Karte',
            'ansicht' => $ansicht,
            'stand' => Koordinatenermittlung::aus($this->dienste)->stand($heute),
            'abteilungen' => $abteilungen,
            'abteilungId' => $abteilungId,
            'status' => $status,
            'gefiltert' => $abteilungId !== null || $status !== MitgliederStatus::Alle,
            'darfErmitteln' => $konto?->rolle->darf(Permission::AdminSystem) === true,
            'neuErmittelnPfad' => self::PFAD_NEU_ERMITTELN,
            'kachelUrl' => self::PFAD_KACHEL . '/{z}/{x}/{y}.png',
            'mitgliedPfad' => Navigation::MITGLIEDER,
            // "Auf der großen Karte zeigen" at a member (issue #106): its place is centred.
            'fokusMitglied' => self::id($request->query['mitglied'] ?? null),
            'scripts' => ['/js/vendor/leaflet/leaflet.js', '/js/karte.js'],
            'styles' => ['/js/vendor/leaflet/leaflet.css'],
        ], Area::App));
    }

    /**
     * One map tile. Not member data: the browser may keep it for a day
     * (private), which spares the server most repeats while panning.
     */
    public function kachel(string $z, string $x, string $y): Response
    {
        // Digits only (route); more than 7 cannot be a tile up to zoom 18,
        // and would not even fit an int.
        if (strlen($z) > 2 || strlen($x) > 7 || strlen($y) > 7 || !KachelProxy::gueltig((int) $z, (int) $x, (int) $y)) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Diese Kachel gibt es nicht.');
        }

        // The browser asks for many tiles at once; holding the session lock
        // would line them all up behind each other.
        $this->dienste->session->schliessen();
        $png = KachelProxy::aus($this->dienste)->kachel((int) $z, (int) $x, (int) $y, $this->jetzt());
        if ($png === null) {
            return new Response(503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Der Kartendienst antwortet gerade nicht.');
        }

        return new Response(200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'Content-Length' => (string) strlen($png),
        ], $png);
    }

    /** One step of "Jetzt Koordinaten ermitteln": a few addresses, then the new state as JSON. */
    public function ermitteln(): Response
    {
        // A step talks to the geocoder for seconds - the user's other tabs
        // must not wait for the session meanwhile.
        $this->dienste->session->schliessen();
        $stand = Koordinatenermittlung::aus($this->dienste)->ermitteln($this->jetzt(), self::SCHRITT_ANZAHL, self::SCHRITT_BUDGET_S);

        return Response::json($stand->toArray());
    }

    /**
     * "Alle neu ermitteln" (issue #108): forgets found, approximate and not
     * found - search addresses and points set by hand stay - after the tick
     * of the question. The script then runs the chain of PFAD_ERMITTELN and
     * gets the new state as JSON; without script the page comes back and
     * the cron does the asking.
     */
    public function neuErmitteln(Request $request): Response
    {
        $hintergrund = $request->header('X-Hintergrund') === '1';
        if ($request->feld('sicher') !== '1') {
            if ($hintergrund) {
                return Response::json(['fehler' => 'Bitte bestätigen Sie die Rückfrage.'], 422);
            }
            $this->dienste->session->flash('Bitte bestätigen Sie die Rückfrage, damit alle Koordinaten neu ermittelt werden.', FlashArt::Fehler);

            return Response::redirect(Navigation::KARTE);
        }

        $ermittlung = Koordinatenermittlung::aus($this->dienste);
        $vergessen = $ermittlung->alleVergessen();
        // A count only - no address (CLAUDE.md section 4).
        $this->dienste->audit()->protokollieren(
            AuditAktion::KarteNeuErmittelt,
            $this->dienste->session->userId(),
            'geo_anschrift',
            null,
            ['vergessen' => $vergessen],
            $request->ip,
        );

        if ($hintergrund) {
            return Response::json($ermittlung->stand($this->jetzt())->toArray());
        }
        $this->dienste->session->flash('Die bisherigen Ergebnisse sind vergessen. Die Koordinaten werden jetzt neu ermittelt – mit „Jetzt Koordinaten ermitteln“ sofort, sonst nach und nach im Hintergrund.', FlashArt::Ok);

        return Response::redirect(Navigation::KARTE);
    }

    private function jetzt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(Datum::ZEITZONE));
    }

    private static function id(mixed $wert): ?int
    {
        return is_string($wert) && preg_match('/^[1-9][0-9]{0,18}$/', $wert) === 1 ? (int) $wert : null;
    }
}
