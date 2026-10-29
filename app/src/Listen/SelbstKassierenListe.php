<?php

declare(strict_types=1);

namespace App\Listen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Domain\Zahlart;
use App\Repository\SelbstKassierenRepository;
use App\Support\Iban;

/**
 * "Selbst kassieren" (docs/spec/09-listen-und-exporte.md section 2): open
 * annual fees of members who pay by themselves or have no valid IBAN, so
 * they can be collected by hand. Filter: the year. Admin only - the
 * permission keeps the Vorstand from seeing it (Rolle::Vorstand has no
 * beitraege.verwalten).
 */
final readonly class SelbstKassierenListe implements Liste
{
    /** Cheap to build: the database is asked only when the list is shown. */
    public function __construct(private Dienste $dienste)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function schluessel(): string
    {
        return 'selbst-kassieren';
    }

    public function titel(): string
    {
        return 'Selbst kassieren';
    }

    public function reihenfolge(): int
    {
        return 40;
    }

    public function recht(): Permission
    {
        return Permission::BeitraegeVerwalten;
    }

    public function beschreibung(): string
    {
        return 'Offene Jahresbeiträge von Selbstzahlern und Mitgliedern ohne gültige IBAN.';
    }

    public function filter(): array
    {
        return [ListenFilter::jahr('jahr', 'Jahr', (int) (new \DateTimeImmutable('today'))->format('Y'))];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $jahr = (int) $filter['jahr'];
        $zeilen = (new SelbstKassierenRepository($this->dienste->pdo()))
            ->offeneBeitraege($jahr, new \DateTimeImmutable('today'));

        return new ListenErgebnis(self::spalten(), self::zeilen($zeilen));
    }

    /** @return list<Spalte> */
    public static function spalten(): array
    {
        return [
            Spalte::text('name', 'Name'),
            Spalte::zahl('jahr', 'Jahr'),
            Spalte::geld('betrag', 'Betrag'),
            Spalte::text('kontakt', 'Kontakt', nebenspalte: true),
        ];
    }

    /** Whether a member's fee is collected by hand: pays by themselves or has no valid IBAN. */
    public static function istSelbstKassieren(Zahlart $zahlart, string $iban): bool
    {
        return $zahlart === Zahlart::Selbst || !Iban::istGueltig($iban);
    }

    /**
     * Rows of the raw query (see SelbstKassierenRepository), only the ones
     * to collect by hand.
     *
     * @param list<array<string, mixed>> $rohzeilen
     * @return list<ListenZeile>
     */
    public static function zeilen(array $rohzeilen): array
    {
        $zeilen = [];
        foreach ($rohzeilen as $z) {
            $zahlart = Zahlart::tryFrom((string) $z['zahlart']) ?? Zahlart::Lastschrift;
            if (!self::istSelbstKassieren($zahlart, (string) $z['iban'])) {
                continue;
            }
            $zeilen[] = new ListenZeile(
                [
                    'name' => implode(', ', array_filter(
                        [(string) $z['nachname'], (string) $z['vorname']],
                        static fn(string $t): bool => $t !== '',
                    )),
                    'jahr' => (int) $z['jahr'],
                    'betrag' => (int) $z['betrag_cent'],
                    'kontakt' => implode(', ', array_filter(
                        [(string) $z['telefon'], (string) $z['handy'], (string) $z['email']],
                        static fn(string $t): bool => $t !== '',
                    )),
                ],
                (int) $z['mitglied_id'],
            );
        }

        return $zeilen;
    }

    public function dateiname(array $filter): string
    {
        return 'selbst-kassieren-' . (int) $filter['jahr'];
    }
}
