<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;
use App\Tests\Support\DatabaseTestCase;

final class VereinRepositoryTest extends DatabaseTestCase
{
    private VereinRepository $verein;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->verein = new VereinRepository($this->pdo(), new SettingRepository($this->pdo()));
    }

    public function testWithoutDataEverythingIsEmpty(): void
    {
        self::assertSame(Vereinsdaten::leer()->alsListe(), $this->verein->get()->alsListe());
        self::assertSame([], $this->verein->verbaende());
    }

    public function testAllFieldsAreSavedAndReadBack(): void
    {
        $werte = [];
        foreach (array_keys(Vereinsdaten::FELDER) as $schluessel) {
            $werte[$schluessel] = in_array($schluessel, ['freistellungsdatum', 'mandatsdatum'], true) ? '2024-05-17' : 'Wert ' . $schluessel . ' äöü';
        }
        $daten = Vereinsdaten::ausEingabe(static fn(string $name): string => $werte[$name]);

        $this->verein->speichern($daten);

        self::assertSame($daten->alsListe(), $this->verein->get()->alsListe());
        self::assertSame('Wert vereinsname äöü', $this->verein->get()->wert('vereinsname'));
    }

    /** @param list<array{string, string}> $zeilen name, nummer */
    private function ersetzen(array $zeilen, array $ids = []): void
    {
        $this->verein->verbaendeErsetzen(array_map(
            static fn(array $z, int $i): array => ['id' => $ids[$i] ?? 0, 'name' => $z[0], 'vereinsnummer' => $z[1], 'bemerkung' => ''],
            $zeilen,
            array_keys($zeilen),
        ));
    }

    public function testAssociationsAreAddedChangedSortedAndDeleted(): void
    {
        $this->ersetzen([['BLSV', '12345'], ['BFV', '99']]);
        $erste = $this->verein->verbaende();
        self::assertSame(['BLSV', 'BFV'], array_map(static fn($v): string => $v->name, $erste));
        self::assertSame([0, 1], array_map(static fn($v): int => $v->sortierung, $erste));

        // Change the number of BFV, swap the order, keep the ids.
        $this->ersetzen([['BFV', '100'], ['BLSV', '12345']], [$erste[1]->id, $erste[0]->id]);
        $neu = $this->verein->verbaende();
        self::assertSame(['BFV', 'BLSV'], array_map(static fn($v): string => $v->name, $neu));
        self::assertSame('100', $neu[0]->vereinsnummer);
        self::assertSame([$erste[1]->id, $erste[0]->id], array_map(static fn($v): int => $v->id, $neu));

        // A row that is missing is deleted.
        $this->ersetzen([['BFV', '100']], [$erste[1]->id]);
        self::assertSame(['BFV'], array_map(static fn($v): string => $v->name, $this->verein->verbaende()));
    }

    public function testFindingAnAssociationByNameIgnoresCase(): void
    {
        $this->ersetzen([['BLSV', '12345']]);

        self::assertSame('12345', $this->verein->verbandMitName('blsv')?->vereinsnummer);
        self::assertNull($this->verein->verbandMitName('BFV'));
    }

    public function testAnUnknownIdIsAddedAsNewRow(): void
    {
        $this->ersetzen([['BLSV', '1']], [9999]);

        self::assertCount(1, $this->verein->verbaende());
    }
}
