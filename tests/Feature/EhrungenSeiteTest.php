<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Tests\Support\AppTestCase;

/** The honours list and its "geehrt" button, end to end. Made-up members only. */
final class EhrungenSeiteTest extends AppTestCase
{
    private \PDO $pdo;

    protected function pdoFabrik(): ?\Closure
    {
        $fabrik = $this->mitDatenbank();
        $this->pdo = $fabrik();

        return $fabrik;
    }

    protected function setUp(): void
    {
        parent::setUp();
        new SettingRepository($this->pdo)->set('ehrungsstufen', [25]);
        $jahr = (int) date('Y') - 25;
        new MitgliedRepository($this->pdo)->speichern(
            new Mitglied(1, 'Beispiel', vorname: 'Erika', eintrittsdatum: new \DateTimeImmutable($jahr . '-03-01')),
            VersionsAnlass::angelegt(),
            1,
        );
    }

    public function testBothRolesCanNoteAndTakeBack(): void
    {
        foreach ([Rolle::Vorstand, Rolle::Admin] as $rolle) {
            $this->als($rolle);
            $seite = $this->get('/app/listen/ehrungen')->body;
            self::assertStringContainsString('Beispiel, Erika', $seite);
            self::assertStringContainsString('>Geehrt</button>', $seite);

            $felder = ['mitglied' => '1', 'stufe' => '25', 'jahr' => (string) date('Y'), 'aktion' => 'geehrt'];
            $antwort = $this->post('/app/listen/ehrungen/vermerk', $felder);
            self::assertWeiterleitungNach('/app/listen/ehrungen?jahr=' . date('Y'), $antwort);
            self::assertStringContainsString('>Zurücknehmen</button>', $this->get('/app/listen/ehrungen')->body);

            self::assertStringNotContainsString('Beispiel, Erika', $this->get('/app/listen/ehrungen', ['nur_offene' => '1'])->body);

            $this->post('/app/listen/ehrungen/vermerk', ['aktion' => 'zurueck'] + $felder);
            self::assertStringContainsString('>Geehrt</button>', $this->get('/app/listen/ehrungen')->body);
        }
    }

    public function testPostNeedsTheCsrfToken(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/listen/ehrungen/vermerk', ['mitglied' => '1', 'stufe' => '25', 'aktion' => 'geehrt'], false);

        self::assertNotSame(302, $antwort->status);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ehrung')->fetchColumn());
    }
}
