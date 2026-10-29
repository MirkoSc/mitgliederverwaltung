<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\App\Mitglied\MitgliedErweiterungen;
use App\App\Mitglied\VerlaufReiter;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\FamilieRepository;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * The tab "Verlauf" and "Zurückholen" (docs/spec/07-mitglieder.md section 7,
 * issue #22) through the whole application. Every name is made up.
 */
final class VerlaufTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function repo(): MitgliedRepository
    {
        return new MitgliedRepository($this->dienste->pdo());
    }

    private function tab(int $id): string
    {
        $konto = $this->als(Rolle::Vorstand);
        $this->dienste->view->setAnmeldung($konto, 'token-9');
        $reiter = MitgliedErweiterungen::reiter()->alle($this->dienste, $konto);
        $verlauf = array_values(array_filter($reiter, static fn(object $r): bool => $r instanceof VerlaufReiter));
        self::assertCount(1, $verlauf);

        return $this->dienste->view->fragment($verlauf[0]->template(), $verlauf[0]->daten($id));
    }

    public function testTheTabListsVersionsNewestFirstWithOldToNew(): void
    {
        $m = $this->repo()->speichern(new Mitglied(14, 'Musterfrau', ort: 'Altdorf'), VersionsAnlass::angelegt(), null)->mitglied;
        $this->repo()->speichern($m->mit(['ort' => 'Neustadt', 'aktiv' => false]), VersionsAnlass::geaendert(), null);

        $html = $this->tab((int) $m->id);

        self::assertLessThan(strpos($html, 'Version 1'), strpos($html, 'Version 2'), 'newest first');
        self::assertStringContainsString('<strong>Ort:</strong>', $html);
        self::assertStringContainsString('Altdorf', $html);
        self::assertStringContainsString('Neustadt', $html);
        self::assertStringContainsString('<strong>Status:</strong>', $html);
        self::assertStringContainsString('aktueller Stand', $html);
        self::assertStringContainsString('action="/app/mitglieder/' . $m->id . '/verlauf/1/zurueckholen"', $html);
        self::assertStringNotContainsString('/verlauf/2/zurueckholen', $html, 'the current state needs no button');
        self::assertStringContainsString('name="_csrf" value="token-9"', $html);
    }

    public function testRestoreAppendsANewVersionAndLeavesTheOldOnes(): void
    {
        $m = $this->repo()->speichern(new Mitglied(14, 'Musterfrau', ort: 'Altdorf'), VersionsAnlass::angelegt(), null)->mitglied;
        $this->repo()->speichern($m->mit(['ort' => 'Neustadt']), VersionsAnlass::geaendert(), null);
        $konto = $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/mitglieder/' . $m->id . '/verlauf/1/zurueckholen');

        self::assertSame(302, $antwort->status);
        self::assertSame('/app/mitglieder?mitglied=' . $m->id . '&reiter=verlauf', $antwort->headers['Location'] ?? '');
        self::assertSame('Altdorf', $this->repo()->finde((int) $m->id)?->ort);
        $versionen = $this->repo()->versionen((int) $m->id);
        self::assertCount(3, $versionen);
        self::assertSame('wiederhergestellt aus Version 1', $versionen[0]->anlass->text);
        self::assertSame($konto->id, $versionen[0]->geaendertVon);
        self::assertSame('Neustadt', $versionen[1]->daten['ort']);
        self::assertStringContainsString('Version 1 ist zurückgeholt', (string) $this->dienste->session->pullFlash()?->text);
        $audit = $this->dienste->pdo()->query("SELECT details FROM audit_log WHERE action = 'mitglied.version_zurueckgeholt'")->fetchColumn();
        self::assertIsString($audit);
        self::assertStringNotContainsString('Musterfrau', $audit, 'no personal data in the log');
    }

    public function testRestoreWithAGoneFamilyLeavesItEmptyAndSaysSo(): void
    {
        $familie = new FamilieRepository($this->dienste->pdo())->anlegen('Familie Muster');
        $m = $this->repo()->speichern(new Mitglied(14, 'Musterfrau', familieId: $familie->id, familieRolle: FamilienRolle::Kind), VersionsAnlass::angelegt(), null)->mitglied;
        $this->repo()->speichern($m->mit(['familieId' => null, 'familieRolle' => null]), VersionsAnlass::geaendert(), null);
        $this->als(Rolle::Admin);

        $this->post('/app/mitglieder/' . $m->id . '/verlauf/1/zurueckholen');

        self::assertNull($this->repo()->finde((int) $m->id)?->familieId);
        $flash = $this->dienste->session->pullFlash();
        self::assertStringContainsString('Die Familie gibt es nicht mehr', (string) $flash?->text);
        // The history still shows the old name.
        self::assertStringContainsString('Familie Muster', $this->tab((int) $m->id));
    }

    public function testRestoringTheCurrentStateWritesNothing(): void
    {
        $m = $this->repo()->speichern(new Mitglied(14, 'Musterfrau'), VersionsAnlass::angelegt(), null)->mitglied;
        $this->als(Rolle::Vorstand);

        $this->post('/app/mitglieder/' . $m->id . '/verlauf/1/zurueckholen');

        self::assertCount(1, $this->repo()->versionen((int) $m->id));
        self::assertStringContainsString('Nichts geändert', (string) $this->dienste->session->pullFlash()?->text);
    }

    public function testUnknownVersionOrMemberIs404(): void
    {
        $m = $this->repo()->speichern(new Mitglied(14, 'Musterfrau'), VersionsAnlass::angelegt(), null)->mitglied;
        $this->als(Rolle::Vorstand);

        self::assertSame(404, $this->post('/app/mitglieder/' . $m->id . '/verlauf/9/zurueckholen')->status);
        self::assertSame(404, $this->post('/app/mitglieder/9999/verlauf/1/zurueckholen')->status);
    }

    public function testRestoreNeedsTheCsrfToken(): void
    {
        $m = $this->repo()->speichern(new Mitglied(14, 'Musterfrau'), VersionsAnlass::angelegt(), null)->mitglied;
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->post('/app/mitglieder/' . $m->id . '/verlauf/1/zurueckholen', [], false)->status);
    }
}
