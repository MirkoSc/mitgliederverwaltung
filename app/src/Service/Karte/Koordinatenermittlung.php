<?php

declare(strict_types=1);

namespace App\Service\Karte;

use App\Dienste;
use App\Repository\GeoAnschriftRepository;
use App\Repository\KartenRepository;
use App\Repository\SettingRepository;
use App\Support\FileLogger;

/**
 * Looks up the coordinates of the members' addresses, a few per call
 * (issue #91): from the cron (App\Service\Cron\KoordinatenTask) and from the
 * admin's button "Jetzt Koordinaten ermitteln" (a short step chain).
 *
 * The rules of the geocoder's usage policy and of this application:
 *   - at most one request per second, across requests: the time of the last
 *     one is a setting, and a database lock keeps the cron and the button
 *     from asking at the same time;
 *   - few addresses per call and a time budget well below the time limit
 *     (CLAUDE.md section 1);
 *   - "not found" is remembered and not asked again until the address
 *     changes (a changed address has another hash);
 *   - a failure of the service is no crash: it is logged without the
 *     address, nothing is stored, and the geocoding rests for a while
 *     before it asks again.
 */
final readonly class Koordinatenermittlung
{
    /** Seconds between two requests; a little over the policy's one second. */
    public const float ABSTAND_S = 1.1;

    /** How long the geocoding rests after a failure of the service. */
    public const int PAUSE_MINUTEN = 15;

    public const string SETTING_LETZTE_ANFRAGE = 'karte_geocoding_letzte_anfrage';

    public const string SETTING_PAUSE_BIS = 'karte_geocoding_pause_bis';

    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private KartenRepository $mitglieder,
        private GeoAnschriftRepository $koordinaten,
        private SettingRepository $settings,
        private Geocoder $geocoder,
        private Takt $takt,
        private ?FileLogger $logger = null,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            new KartenRepository($d->pdo()),
            new GeoAnschriftRepository($d->pdo()),
            $d->einstellungen(),
            new Geocoder(KartenDienste::http($d), KartenDienste::userAgent($d)),
            $d->dienst(Takt::class, static fn(): Takt => new SystemTakt()),
            $d->logger,
        ));
    }

    /** The counts without asking anybody - for the page. */
    public function stand(\DateTimeImmutable $jetzt): ErmittlungsStand
    {
        return $this->zaehlen($this->mitglieder->anschriften($jetzt), $this->koordinaten->alle(), 0, $this->pauseBis($jetzt));
    }

    /**
     * Asks the geocoder for up to $hoechstens open addresses, stopping early
     * when $zeitbudget seconds are used up.
     */
    public function ermitteln(\DateTimeImmutable $jetzt, int $hoechstens, float $zeitbudget): ErmittlungsStand
    {
        $anschriften = $this->mitglieder->anschriften($jetzt);

        if (!$this->koordinaten->sperren()) {
            $stand = $this->zaehlen($anschriften, $this->koordinaten->alle(), 0, $this->pauseBis($jetzt));

            return new ErmittlungsStand($stand->gesamt, $stand->gefunden, $stand->nichtGefunden, laeuftBereits: true, pauseBis: $stand->pauseBis);
        }

        try {
            // Addresses no current member has any more leave the table.
            $this->koordinaten->entfernenAusser(array_keys($anschriften));
            $bekannt = $this->koordinaten->alle();
            $pauseBis = $this->pauseBis($jetzt);
            $bearbeitet = 0;
            $fehler = '';

            if ($pauseBis === null) {
                $start = $this->takt->jetzt();
                foreach ($anschriften as $hash => $anschrift) {
                    if (isset($bekannt[$hash])) {
                        continue;
                    }
                    if ($bearbeitet >= $hoechstens || $this->takt->jetzt() - $start >= $zeitbudget) {
                        break;
                    }

                    $this->abstandHalten();
                    $ergebnis = $this->geocoder->suchen($anschrift);
                    $this->settings->set(self::SETTING_LETZTE_ANFRAGE, $this->takt->jetzt());

                    if ($ergebnis->istFehler() || $ergebnis->status === null) {
                        $fehler = $ergebnis->fehler;
                        $pauseBis = $jetzt->modify(sprintf('+%d minutes', self::PAUSE_MINUTEN));
                        $this->settings->set(self::SETTING_PAUSE_BIS, $pauseBis->format(self::FORMAT));
                        // The reason only - never the address (CLAUDE.md section 4).
                        $this->logger?->append(sprintf('Geocoding paused for %d minutes: %s', self::PAUSE_MINUTEN, $fehler));
                        break;
                    }

                    $this->koordinaten->speichern($hash, $ergebnis->status, $ergebnis->lat, $ergebnis->lon, $jetzt);
                    $bekannt[$hash] = ['status' => $ergebnis->status, 'lat' => $ergebnis->lat, 'lon' => $ergebnis->lon];
                    $bearbeitet++;
                }
            }

            $stand = $this->zaehlen($anschriften, $bekannt, $bearbeitet, $pauseBis);

            return new ErmittlungsStand($stand->gesamt, $stand->gefunden, $stand->nichtGefunden, $bearbeitet, pauseBis: $pauseBis, fehler: $fehler);
        } finally {
            $this->koordinaten->freigeben();
        }
    }

    /** Waits until the last request is ABSTAND_S ago - whoever sent it. */
    private function abstandHalten(): void
    {
        $letzte = $this->settings->get(self::SETTING_LETZTE_ANFRAGE);
        if (!is_int($letzte) && !is_float($letzte)) {
            return;
        }
        $warten = (float) $letzte + self::ABSTAND_S - $this->takt->jetzt();
        if ($warten > 0) {
            // A clock that jumped back must not park the request for long.
            $this->takt->warten(min($warten, self::ABSTAND_S));
        }
    }

    private function pauseBis(\DateTimeImmutable $jetzt): ?\DateTimeImmutable
    {
        $wert = $this->settings->text(self::SETTING_PAUSE_BIS);
        if ($wert === '') {
            return null;
        }
        $bis = \DateTimeImmutable::createFromFormat(self::FORMAT, $wert);
        if ($bis === false || $bis <= $jetzt) {
            return null;
        }

        return $bis;
    }

    /**
     * @param array<string, Anschrift> $anschriften
     * @param array<string, array{status: GeoStatus, lat: ?float, lon: ?float}> $bekannt
     */
    private function zaehlen(array $anschriften, array $bekannt, int $bearbeitet, ?\DateTimeImmutable $pauseBis): ErmittlungsStand
    {
        $gefunden = 0;
        $nichtGefunden = 0;
        foreach (array_keys($anschriften) as $hash) {
            match ($bekannt[$hash]['status'] ?? null) {
                GeoStatus::Gefunden => $gefunden++,
                GeoStatus::NichtGefunden => $nichtGefunden++,
                null => null,
            };
        }

        return new ErmittlungsStand(count($anschriften), $gefunden, $nichtGefunden, $bearbeitet, pauseBis: $pauseBis);
    }
}
