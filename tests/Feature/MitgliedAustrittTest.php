<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\App\Mitglied\DatenReiter;
use App\App\Mitglied\MitgliedAnsicht;
use App\App\Mitglied\Einbettung;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * Leaving instead of deleting (docs/spec/07-mitglieder.md section 1): the
 * button "Mitglied ausgetreten" with date and reason, a leaving date in the
 * future only noted, "Austritt zurücknehmen", and deleting only a former
 * member, asked twice with the member number typed in. Rights: "Nur
 * lesen" none of it, the Vorstand leaving but not deleting. Made-up data
 * only.
 */
final class MitgliedAustrittTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function repo(): MitgliedRepository
    {
        return new MitgliedRepository($this->dienste->pdo());
    }

    /** @param array<string, mixed> $mehr */
    private function mitglied(int $nummer = 21, array $mehr = []): Mitglied
    {
        return $this->repo()->speichern(new Mitglied($nummer, 'Musterfrau', vorname: 'Erika')->mit($mehr), VersionsAnlass::angelegt(), null)->mitglied;
    }

    private static function heute(int $tage = 0): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'))->modify(sprintf('%+d days', $tage));
    }

    private function flash(): ?string
    {
        return $this->dienste->session->pullFlash()?->text;
    }

    private function versionen(int $id): int
    {
        return (int) $this->dienste->pdo()->query('SELECT COUNT(*) FROM mitglied_version WHERE mitglied_id = ' . $id)->fetchColumn();
    }

    /** @return list<array{action: string, entity_id: string, details: string}> */
    private function audit(): array
    {
        return $this->dienste->pdo()->query("SELECT action, entity_id, details FROM audit_log WHERE action LIKE 'mitglied.%' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function datenReiter(int $id): string
    {
        return $this->dienste->view->fragment('mitglied/daten', DatenReiter::erzeugen($this->dienste)->daten($id));
    }

    public function testTheTabOffersLeavingInsteadOfDeleting(): void
    {
        $id = (int) $this->mitglied()->id;
        $admin = $this->als(Rolle::Admin);
        $this->dienste->view->setAnmeldung($admin, 'token');

        $html = $this->datenReiter($id);

        self::assertStringContainsString('href="/app/mitglieder/' . $id . '/austritt">Mitglied ausgetreten</a>', $html);
        self::assertStringNotContainsString('Mitglied löschen', $html, 'an active member is never deleted');
        self::assertStringNotContainsString('Austritt zurücknehmen', $html);
    }

    public function testLeavingTodayMakesAFormerMemberAsANewVersion(): void
    {
        $m = $this->mitglied();
        $id = (int) $m->id;
        $this->als(Rolle::Vorstand);

        $seite = $this->get("/app/mitglieder/$id/austritt");
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('<h1>Mitglied ausgetreten</h1>', $seite->body);
        self::assertStringContainsString('name="austrittsdatum" value="' . self::heute()->format('Y-m-d') . '"', $seite->body, 'today by default');
        self::assertStringContainsString('<option value="verstorben">', $seite->body);
        self::assertStringContainsString('name="version" value="1"', $seite->body);

        $antwort = $this->post("/app/mitglieder/$id/austritt", [
            'austrittsdatum' => self::heute()->format('d.m.Y'),
            'austrittsgrund' => 'verstorben',
            'version' => '1',
        ]);

        self::assertWeiterleitungNach('/app/mitglieder?mitglied=' . $id, $antwort);
        self::assertStringStartsWith('Austritt zum ' . self::heute()->format('d.m.Y') . ' eingetragen', (string) $this->flash());
        $gespeichert = $this->repo()->finde($id);
        self::assertSame(self::heute()->format('Y-m-d'), $gespeichert?->austrittsdatum?->format('Y-m-d'));
        self::assertSame('verstorben', $gespeichert->austrittsgrund);
        self::assertTrue($gespeichert->istEhemalig(self::heute()));
        self::assertSame(2, $this->versionen($id));
        self::assertSame([['action' => 'mitglied.austritt', 'entity_id' => (string) $id, 'details' => '{"version":2}']], $this->audit(), 'id and version only, no name');
    }

    public function testALeavingDateInTheFutureIsOnlyNoted(): void
    {
        $id = (int) $this->mitglied()->id;
        $konto = $this->als(Rolle::Vorstand);
        $spaeter = self::heute(30);

        $this->post("/app/mitglieder/$id/austritt", ['austrittsdatum' => $spaeter->format('Y-m-d'), 'austrittsgrund' => 'Austritt', 'version' => '1']);

        self::assertStringStartsWith('Austritt zum ' . $spaeter->format('d.m.Y') . ' vermerkt', (string) $this->flash());
        $this->dienste->view->setAnmeldung($konto, 'token');
        $html = MitgliedAnsicht::aus($this->dienste)->zeigen($id, 'daten', null, Einbettung::mitgliederSeite())->html;
        self::assertStringContainsString('Austritt zum ' . $spaeter->format('d.m.Y') . ' vermerkt', $html);
        self::assertStringNotContainsString('>ehemalig<', $html, 'still a member until then');
        self::assertStringContainsString('Austritt zurücknehmen', $html);
        self::assertStringNotContainsString('Mitglied ausgetreten</a>', $html);
    }

    public function testAnUnreadableDateSavesNothingAndKeepsTheInput(): void
    {
        $id = (int) $this->mitglied()->id;
        $this->als(Rolle::Vorstand);

        foreach (['', '31.02.20x6'] as $datum) {
            $antwort = $this->post("/app/mitglieder/$id/austritt", ['austrittsdatum' => $datum, 'austrittsgrund' => 'Umzug', 'version' => '1']);
            self::assertSame(422, $antwort->status);
            self::assertStringContainsString('class="feld-fehler"', $antwort->body);
            self::assertStringContainsString('value="Umzug"', $antwort->body);
        }
        self::assertNull($this->repo()->finde($id)?->austrittsdatum);
        self::assertSame(1, $this->versionen($id));
    }

    public function testASaveInBetweenIsNotOverwrittenSilently(): void
    {
        $m = $this->mitglied();
        $id = (int) $m->id;
        $this->repo()->speichern($m->mit(['ort' => 'Musterstadt']), VersionsAnlass::geaendert(), null);
        $this->als(Rolle::Vorstand);

        $antwort = $this->post("/app/mitglieder/$id/austritt", ['austrittsdatum' => self::heute()->format('Y-m-d'), 'version' => '1']);

        self::assertSame(409, $antwort->status);
        self::assertStringContainsString('Jemand anderes hat dieses Mitglied gespeichert', $antwort->body);
        self::assertStringContainsString('name="version" value="2"', $antwort->body, 'sending again wins');
        self::assertNull($this->repo()->finde($id)?->austrittsdatum);

        $this->post("/app/mitglieder/$id/austritt", ['austrittsdatum' => self::heute()->format('Y-m-d'), 'version' => '2']);
        self::assertNotNull($this->repo()->finde($id)?->austrittsdatum);
        self::assertSame('Musterstadt', $this->repo()->finde($id)?->ort, 'only leaving date and reason change');
    }

    public function testTakingBackClearsDateAndReasonAfterAShortQuestion(): void
    {
        $id = (int) $this->mitglied(mehr: ['austrittsdatum' => self::heute(-10), 'austrittsgrund' => 'Austritt'])->id;
        $this->als(Rolle::Vorstand);

        $frage = $this->get("/app/mitglieder/$id/austritt-zuruecknehmen", ['zurueck' => '/app/listen/geburtstage']);
        self::assertSame(200, $frage->status);
        self::assertStringContainsString('Austritt zurücknehmen?', $frage->body);
        self::assertStringContainsString('Ausgetreten am ' . self::heute(-10)->format('d.m.Y') . ' (Austritt)', $frage->body);
        self::assertStringContainsString('<input type="hidden" name="zurueck" value="/app/listen/geburtstage">', $frage->body);

        $antwort = $this->post("/app/mitglieder/$id/austritt-zuruecknehmen", ['version' => '1', 'zurueck' => '/app/listen/geburtstage']);

        self::assertWeiterleitungNach('/app/listen/geburtstage?mitglied=' . $id, $antwort);
        self::assertStringStartsWith('Austritt zurückgenommen.', (string) $this->flash());
        $m = $this->repo()->finde($id);
        self::assertNull($m?->austrittsdatum);
        self::assertSame('', $m->austrittsgrund);
        self::assertSame(2, $this->versionen($id));
        self::assertSame('mitglied.austritt_zurueckgenommen', $this->audit()[0]['action'] ?? null);

        // Nothing left to take back: back to the member.
        self::assertWeiterleitungNach('/app/mitglieder?mitglied=' . $id, $this->get("/app/mitglieder/$id/austritt-zuruecknehmen"));
        self::assertSame(302, $this->post("/app/mitglieder/$id/austritt-zuruecknehmen", ['version' => '2'])->status);
        self::assertSame(2, $this->versionen($id));
    }

    public function testDeletingAnActiveOrNotedMemberIsRefused(): void
    {
        $aktiv = (int) $this->mitglied(21)->id;
        $vermerkt = (int) $this->mitglied(22, ['austrittsdatum' => self::heute(1)])->id;
        $this->als(Rolle::Admin);

        foreach ([$aktiv, $vermerkt] as $id) {
            $frage = $this->get("/app/mitglieder/$id/loeschen", ['schritt' => '2']);
            self::assertSame(409, $frage->status);
            self::assertStringContainsString('Löschen lassen sich nur ehemalige Mitglieder.', $frage->body);
            self::assertStringNotContainsString('name="mitgliedsnummer"', $frage->body);

            $nummer = (string) $this->repo()->finde($id)?->mitgliedsnummer;
            self::assertSame(409, $this->post("/app/mitglieder/$id/loeschen", ['mitgliedsnummer' => $nummer])->status);
            self::assertNotNull($this->repo()->finde($id), 'checked on the server, not only by the page');
        }
        self::assertStringContainsString('vermerkt', $this->get("/app/mitglieder/$vermerkt/loeschen")->body);
    }

    public function testAFormerMemberIsDeletedOnlyWithItsNumberTypedIn(): void
    {
        $id = (int) $this->mitglied(57, ['austrittsdatum' => self::heute()])->id;
        $admin = $this->als(Rolle::Admin);
        $this->dienste->view->setAnmeldung($admin, 'token');
        self::assertStringContainsString('href="/app/mitglieder/' . $id . '/loeschen">Mitglied löschen</a>', $this->datenReiter($id));

        $schritt1 = $this->get("/app/mitglieder/$id/loeschen");
        self::assertSame(200, $schritt1->status);
        self::assertStringContainsString('Mitglied löschen?', $schritt1->body);
        self::assertStringContainsString('href="/app/mitglieder/' . $id . '/loeschen?schritt=2">Weiter</a>', $schritt1->body);
        self::assertStringNotContainsString('action="/app/mitglieder/' . $id . '/loeschen"', $schritt1->body, 'step 1 deletes nothing');

        $schritt2 = $this->get("/app/mitglieder/$id/loeschen", ['schritt' => '2']);
        self::assertStringContainsString('Endgültig löschen?', $schritt2->body);
        self::assertStringContainsString('Mitgliedsnummer zur Bestätigung eintippen', $schritt2->body);
        self::assertStringContainsString('name="mitgliedsnummer" required', $schritt2->body);

        foreach (['' => 'Bitte die Mitgliedsnummer eintippen.', '58' => 'Die Mitgliedsnummer stimmt nicht.', 'x57' => 'Die Mitgliedsnummer stimmt nicht.'] as $eingabe => $meldung) {
            $antwort = $this->post("/app/mitglieder/$id/loeschen", ['mitgliedsnummer' => (string) $eingabe]);
            self::assertSame(422, $antwort->status);
            self::assertStringContainsString($meldung, $antwort->body);
            self::assertNotNull($this->repo()->finde($id));
        }

        self::assertWeiterleitungNach('/app/mitglieder', $this->post("/app/mitglieder/$id/loeschen", ['mitgliedsnummer' => '57']));
        self::assertSame('Mitglied Nr. 57 ist gelöscht.', $this->flash());
        self::assertNull($this->repo()->finde($id));
    }

    public function testRights(): void
    {
        $id = (int) $this->mitglied(mehr: ['austrittsdatum' => self::heute(-1)])->id;

        $this->als(Rolle::Lesend);
        foreach (["/app/mitglieder/$id/austritt", "/app/mitglieder/$id/austritt-zuruecknehmen", "/app/mitglieder/$id/loeschen"] as $pfad) {
            self::assertSame(403, $this->get($pfad)->status, $pfad);
            self::assertSame(403, $this->post($pfad, ['austrittsdatum' => '2026-01-01', 'mitgliedsnummer' => '21', 'version' => '1'])->status, $pfad);
        }

        $vorstand = $this->als(Rolle::Vorstand);
        self::assertSame(200, $this->get("/app/mitglieder/$id/austritt")->status);
        self::assertSame(200, $this->get("/app/mitglieder/$id/austritt-zuruecknehmen")->status);
        self::assertSame(403, $this->get("/app/mitglieder/$id/loeschen")->status);
        self::assertSame(403, $this->post("/app/mitglieder/$id/loeschen", ['mitgliedsnummer' => '21'])->status);
        $this->dienste->view->setAnmeldung($vorstand, 'token');
        self::assertStringNotContainsString('Mitglied löschen', $this->datenReiter($id), 'the Vorstand never deletes');

        self::assertNotNull($this->repo()->finde($id));
        self::assertSame(1, $this->versionen($id));
    }
}
