<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Permission;
use App\Domain\Rolle;
use PHPUnit\Framework\TestCase;

/**
 * The three fixed roles against docs/spec/02-sicherheit.md section 6 - spelled
 * out right by right, so the `match` in App\Domain\Rolle cannot drift.
 */
final class RolleTest extends TestCase
{
    public function testTheAdminMayDoEverything(): void
    {
        foreach (Permission::cases() as $recht) {
            self::assertTrue(Rolle::Admin->darf($recht), $recht->value);
        }
    }

    public function testTheBoardWorksWithMembersAndListsAndSeesPaidStatus(): void
    {
        $erwartet = [
            'mitglieder.sehen' => true,
            'mitglieder.bearbeiten' => true,
            'mitglieder.loeschen' => false,
            'listen.sehen' => true,
            'beitraege.sehen' => true,
            'beitraege.verwalten' => false,
            'admin.einstellungen' => false,
            'admin.benutzer' => false,
            'admin.import' => false,
            'admin.system' => false,
            'admin.audit' => false,
        ];

        $tatsaechlich = [];
        foreach (Permission::cases() as $recht) {
            $tatsaechlich[$recht->value] = Rolle::Vorstand->darf($recht);
        }

        self::assertSame($erwartet, $tatsaechlich);
    }

    /** "Nur lesen" (issue #87): sees members, lists and the paid status, changes nothing. */
    public function testReadOnlySeesMembersListsAndPaidStatusOnly(): void
    {
        $erwartet = [
            'mitglieder.sehen' => true,
            'mitglieder.bearbeiten' => false,
            'mitglieder.loeschen' => false,
            'listen.sehen' => true,
            'beitraege.sehen' => true,
            'beitraege.verwalten' => false,
            'admin.einstellungen' => false,
            'admin.benutzer' => false,
            'admin.import' => false,
            'admin.system' => false,
            'admin.audit' => false,
        ];

        $tatsaechlich = [];
        foreach (Permission::cases() as $recht) {
            $tatsaechlich[$recht->value] = Rolle::Lesend->darf($recht);
        }

        self::assertSame($erwartet, $tatsaechlich);
    }

    /** Neither the board nor "Nur lesen" holds any admin.* right (no admin area). */
    public function testOnlyTheAdminHoldsAdminRights(): void
    {
        foreach ([Rolle::Vorstand, Rolle::Lesend] as $rolle) {
            foreach ($rolle->rechte() as $recht) {
                self::assertFalse($recht->istAdmin(), $rolle->value . ' holds ' . $recht->value);
            }
        }
    }

    public function testTheStoredValuesAreStable(): void
    {
        self::assertSame(['admin', 'vorstand', 'lesend'], array_map(static fn(Rolle $r): string => $r->value, Rolle::cases()));
    }

    public function testTheRolesHaveGermanLabels(): void
    {
        self::assertSame(['Admin', 'Vorstand', 'Nur lesen'], array_map(static fn(Rolle $r): string => $r->bezeichnung(), Rolle::cases()));
    }

    public function testEveryRightHasALabel(): void
    {
        foreach (Permission::cases() as $recht) {
            self::assertNotSame('', $recht->bezeichnung());
        }
    }
}
