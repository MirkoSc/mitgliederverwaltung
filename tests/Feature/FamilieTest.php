<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\App\Mitglied\FamilieReiter;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\FamilieRepository;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * The family of a member through the whole application (docs/spec/
 * 07-mitglieder.md sections 3, 7 and 9; issue #20): create, join, change
 * the role, leave; an empty family disappears; every change is a version.
 * All names are made up.
 */
final class FamilieTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function repo(): MitgliedRepository
    {
        return new MitgliedRepository($this->dienste->pdo());
    }

    private function mitglied(int $nummer, string $nachname = 'Musterfrau'): Mitglied
    {
        return $this->repo()->speichern(new Mitglied($nummer, $nachname), VersionsAnlass::angelegt(), null)->mitglied;
    }

    private function anzahlFamilien(): int
    {
        return (int) $this->dienste->pdo()->query('SELECT COUNT(*) FROM familie')->fetchColumn();
    }

    private function neu(int $id, string $name = 'Familie Muster', string $rolle = 'zahler'): void
    {
        $antwort = $this->post("/app/mitglieder/$id/familie/neu", ['name' => $name, 'rolle' => $rolle]);
        self::assertSame(302, $antwort->status);
    }

    public function testCreatingAFamilyPutsTheMemberInItWithTheRoleAndAVersion(): void
    {
        $this->als(Rolle::Vorstand);
        $m = $this->mitglied(1);

        $this->neu((int) $m->id);

        $danach = $this->repo()->finde((int) $m->id);
        self::assertNotNull($danach);
        self::assertNotNull($danach->familieId);
        self::assertSame(FamilienRolle::Zahler, $danach->familieRolle);
        self::assertSame('Familie Muster', new FamilieRepository($this->dienste->pdo())->finde($danach->familieId)?->name);
        self::assertSame(2, $danach->version);
        $versionen = $this->repo()->versionen((int) $m->id);
        self::assertSame('geaendert', $versionen[0]->anlass->text);
        self::assertSame('Familie Muster', $versionen[0]->daten['bezeichnungen']['familie'] ?? null);
    }

    public function testAnEmptyNameProposesTheSurname(): void
    {
        $this->als(Rolle::Vorstand);
        $m = $this->mitglied(1, 'Beispiel');

        $this->neu((int) $m->id, '  ');

        $familie = $this->repo()->finde((int) $m->id)?->familieId;
        self::assertSame('Familie Beispiel', new FamilieRepository($this->dienste->pdo())->finde((int) $familie)?->name);
    }

    public function testJoiningAFamilyShowsTheOtherPersons(): void
    {
        $this->als(Rolle::Admin);
        $a = $this->mitglied(1, 'Muster');
        $b = $this->mitglied(2, 'Muster');
        $this->neu((int) $a->id);
        $familieId = (int) $this->repo()->finde((int) $a->id)?->familieId;

        $antwort = $this->post('/app/mitglieder/' . $b->id . '/familie/beitreten', ['familie_id' => (string) $familieId, 'rolle' => 'kind']);

        self::assertSame(302, $antwort->status);
        $bNeu = $this->repo()->finde((int) $b->id);
        self::assertSame($familieId, $bNeu?->familieId);
        self::assertSame(FamilienRolle::Kind, $bNeu?->familieRolle);
        $daten = new FamilieReiter($this->dienste)->daten((int) $b->id);
        self::assertSame([(int) $a->id], array_map(static fn(Mitglied $p): ?int => $p->id, $daten['andere']));
        self::assertSame(1, $this->anzahlFamilien());
    }

    public function testChangingTheRoleIsANewVersion(): void
    {
        $this->als(Rolle::Vorstand);
        $m = $this->mitglied(1);
        $this->neu((int) $m->id);

        $this->post('/app/mitglieder/' . $m->id . '/familie/rolle', ['rolle' => 'elternteil']);

        $danach = $this->repo()->finde((int) $m->id);
        self::assertSame(FamilienRolle::Elternteil, $danach?->familieRolle);
        self::assertSame(3, $danach?->version);
    }

    public function testLeavingDeletesTheEmptyFamilyButKeepsAFamilyWithOthers(): void
    {
        $this->als(Rolle::Vorstand);
        $a = $this->mitglied(1);
        $b = $this->mitglied(2);
        $this->neu((int) $a->id);
        $familieId = (string) $this->repo()->finde((int) $a->id)?->familieId;
        $this->post('/app/mitglieder/' . $b->id . '/familie/beitreten', ['familie_id' => $familieId, 'rolle' => 'kind']);

        $this->post('/app/mitglieder/' . $b->id . '/familie/verlassen');
        self::assertNull($this->repo()->finde((int) $b->id)?->familieId);
        self::assertNull($this->repo()->finde((int) $b->id)?->familieRolle);
        self::assertSame(1, $this->anzahlFamilien(), 'the payer is still in it');

        $this->post('/app/mitglieder/' . $a->id . '/familie/verlassen');
        self::assertSame(0, $this->anzahlFamilien(), 'nobody left: deleted');
    }

    public function testSearchFindsFamiliesByNameAndOffersTheNewOne(): void
    {
        $this->als(Rolle::Vorstand);
        $a = $this->mitglied(1);
        $b = $this->mitglied(2, 'Beispiel');
        $this->neu((int) $a->id, 'Familie Muster');

        $seite = $this->get('/app/mitglieder/' . $b->id . '/familie', ['q' => 'muster']);

        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Familie Muster', $seite->body);
        self::assertStringContainsString('Dieser Familie beitreten', $seite->body);
        self::assertStringContainsString('value="Familie Beispiel"', $seite->body);
        self::assertStringNotContainsString('Dieser Familie beitreten', $this->get('/app/mitglieder/' . $b->id . '/familie', ['q' => 'zzz'])->body);
    }

    public function testRenamingAndAGoneFamily(): void
    {
        $this->als(Rolle::Vorstand);
        $m = $this->mitglied(1);
        $this->neu((int) $m->id);

        $this->post('/app/mitglieder/' . $m->id . '/familie/umbenennen', ['name' => 'Familie Neu']);
        $familieId = (int) $this->repo()->finde((int) $m->id)?->familieId;
        self::assertSame('Familie Neu', new FamilieRepository($this->dienste->pdo())->finde($familieId)?->name);

        $b = $this->mitglied(2);
        $this->post('/app/mitglieder/' . $b->id . '/familie/beitreten', ['familie_id' => '9999', 'rolle' => 'kind']);
        self::assertNull($this->repo()->finde((int) $b->id)?->familieId);
    }

    public function testUnknownMemberIs404(): void
    {
        $this->als(Rolle::Vorstand);

        self::assertSame(404, $this->get('/app/mitglieder/999/familie')->status);
        self::assertSame(404, $this->post('/app/mitglieder/999/familie/verlassen')->status);
    }

    public function testSectionIsOfferedAndShowsFamilyAndRole(): void
    {
        $this->als(Rolle::Vorstand);
        $m = $this->mitglied(1);
        self::assertNull(new FamilieReiter($this->dienste)->daten((int) $m->id)['familie']);
        $this->neu((int) $m->id);

        $html = $this->dienste->view->fragment('mitglied/familie', new FamilieReiter($this->dienste)->daten((int) $m->id));

        self::assertStringContainsString('Familie Muster', $html);
        self::assertStringContainsString('Aus der Familie nehmen', $html);
        self::assertStringContainsString('<option value="zahler" selected>', $html);
    }
}
