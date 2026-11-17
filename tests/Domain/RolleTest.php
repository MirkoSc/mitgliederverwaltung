<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Permission;
use App\Domain\Rolle;
use PHPUnit\Framework\TestCase;

/**
 * The four fixed roles against docs/spec/02-sicherheit.md section 6 - spelled
 * out right by right, so the `match` in App\Domain\Rolle cannot drift.
 */
final class RolleTest extends TestCase
{
    /** @return array<string, bool> right => granted, in the order of Permission::cases() */
    private static function rechte(Rolle $rolle): array
    {
        $tatsaechlich = [];
        foreach (Permission::cases() as $recht) {
            $tatsaechlich[$recht->value] = $rolle->darf($recht);
        }

        return $tatsaechlich;
    }

    public function testTheAdminMayDoEverything(): void
    {
        foreach (Permission::cases() as $recht) {
            self::assertTrue(Rolle::Admin->darf($recht), $recht->value);
        }
    }

    /**
     * "Verwaltung": everything of the Vorstand, plus fees with direct debit
     * and the business settings - not deleting members, import, users or
     * anything technical.
     */
    public function testVerwaltungRunsFeesAndBusinessSettingsButNothingTechnical(): void
    {
        self::assertSame([
            'mitglieder.sehen' => true,
            'mitglieder.bearbeiten' => true,
            'bankdaten.sehen' => true,
            'mitglieder.loeschen' => false,
            'listen.sehen' => true,
            'beitraege.sehen' => true,
            'beitraege.verwalten' => true,
            'beitraege.auswerten' => true,
            'admin.verein' => true,
            'admin.einstellungen' => false,
            'admin.benutzer' => false,
            'admin.import' => false,
            'admin.system' => false,
            'admin.audit' => false,
        ], self::rechte(Rolle::Verwaltung));
    }

    public function testTheBoardWorksWithMembersAndListsAndSeesPaidStatus(): void
    {
        self::assertSame([
            'mitglieder.sehen' => true,
            'mitglieder.bearbeiten' => true,
            'bankdaten.sehen' => true,
            'mitglieder.loeschen' => false,
            'listen.sehen' => true,
            'beitraege.sehen' => true,
            'beitraege.verwalten' => false,
            'beitraege.auswerten' => true,
            'admin.verein' => false,
            'admin.einstellungen' => false,
            'admin.benutzer' => false,
            'admin.import' => false,
            'admin.system' => false,
            'admin.audit' => false,
        ], self::rechte(Rolle::Vorstand));
    }

    /**
     * "Nur lesen" (issue #87): sees members, lists and the paid status,
     * changes nothing; no bank details and no fee statistics.
     */
    public function testReadOnlySeesMembersListsAndPaidStatusOnly(): void
    {
        self::assertSame([
            'mitglieder.sehen' => true,
            'mitglieder.bearbeiten' => false,
            'bankdaten.sehen' => false,
            'mitglieder.loeschen' => false,
            'listen.sehen' => true,
            'beitraege.sehen' => true,
            'beitraege.verwalten' => false,
            'beitraege.auswerten' => false,
            'admin.verein' => false,
            'admin.einstellungen' => false,
            'admin.benutzer' => false,
            'admin.import' => false,
            'admin.system' => false,
            'admin.audit' => false,
        ], self::rechte(Rolle::Lesend));
    }

    /**
     * Neither the board nor "Nur lesen" holds any admin.* right (no admin
     * area); "Verwaltung" holds admin.verein and no other.
     */
    public function testOnlyTheAdminHoldsTheTechnicalAdminRights(): void
    {
        foreach ([Rolle::Vorstand, Rolle::Lesend] as $rolle) {
            foreach ($rolle->rechte() as $recht) {
                self::assertFalse($recht->istAdmin(), $rolle->value . ' holds ' . $recht->value);
            }
        }
        $verwaltung = array_values(array_filter(Rolle::Verwaltung->rechte(), static fn(Permission $r): bool => $r->istAdmin()));
        self::assertSame([Permission::AdminVerein], $verwaltung);
    }

    /**
     * The member form shows IBAN, BIC and mandate: whoever may edit members
     * must also see bank details, or a save would work on data the account
     * cannot see (02 section 6).
     */
    public function testWhoeverEditsMembersSeesBankDetails(): void
    {
        foreach (Rolle::cases() as $rolle) {
            if ($rolle->darf(Permission::MitgliederBearbeiten)) {
                self::assertTrue($rolle->darf(Permission::BankdatenSehen), $rolle->value);
            }
        }
    }

    public function testTheStoredValuesAreStable(): void
    {
        self::assertSame(['admin', 'verwaltung', 'vorstand', 'lesend'], array_map(static fn(Rolle $r): string => $r->value, Rolle::cases()));
    }

    public function testTheRolesHaveGermanLabelsInOrder(): void
    {
        self::assertSame(['Admin', 'Verwaltung', 'Vorstand', 'Nur lesen'], array_map(static fn(Rolle $r): string => $r->bezeichnung(), Rolle::cases()));
    }

    /** Each value fits the column `user.rolle` (VARCHAR(16), migration 015) - no migration needed. */
    public function testEveryValueFitsTheColumn(): void
    {
        foreach (Rolle::cases() as $rolle) {
            self::assertLessThanOrEqual(16, strlen($rolle->value), $rolle->value);
        }
    }

    public function testEveryRightHasALabel(): void
    {
        foreach (Permission::cases() as $recht) {
            self::assertNotSame('', $recht->bezeichnung());
        }
    }
}
