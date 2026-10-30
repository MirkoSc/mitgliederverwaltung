<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\SpeicherErgebnis;
use App\Domain\VersionsAnlass;
use App\Domain\Wohnanschrift;
use App\Repository\MitgliedRepository;
use App\Repository\VersionKonfliktException;
use App\Service\Audit\AuditLog;

/**
 * Changing families (docs/spec/07-mitglieder.md section 3): the one place
 * the tab "Familie" of a member (App\App\FamilieController) and the
 * families page (App\App\FamilienController, issue #92) change who belongs
 * to a family, with which role and at which address.
 *
 * Every change is a normal save of the member - a new version with the
 * occasion `geaendert`, guarded by its version number. A family that loses
 * its last person is deleted by MitgliedRepository.
 */
final readonly class FamilienPflege
{
    public function __construct(
        private MitgliedRepository $mitglieder,
        private AuditLog $audit,
        private ?int $userId,
    ) {
    }

    /** As the account logged in right now (not shared: the account may change). */
    public static function aus(Dienste $dienste): self
    {
        return new self(new MitgliedRepository($dienste->pdo()), $dienste->audit(), $dienste->session->userId());
    }

    /**
     * Puts the member into a family with a role, or out of it (null).
     *
     * @throws VersionKonfliktException somebody else saved the member meanwhile
     */
    public function setzen(Mitglied $mitglied, ?int $familieId, ?FamilienRolle $rolle): SpeicherErgebnis
    {
        return $this->speichern($mitglied->mit(['familieId' => $familieId, 'familieRolle' => $familieId === null ? null : $rolle]));
    }

    /**
     * "Zahler wechseln": the member becomes the payer, whoever was the
     * payer before becomes a parent. Returns how many persons changed.
     *
     * @throws \InvalidArgumentException the member is not in this family
     * @throws VersionKonfliktException somebody else saved one of them meanwhile
     */
    public function zahlerWechseln(int $familieId, int $neuerZahlerId): int
    {
        $personen = $this->mitglieder->derFamilie($familieId);
        $neu = self::person($personen, $neuerZahlerId);
        $geaendert = 0;
        foreach ($personen as $person) {
            if ($person->id !== $neu->id && $person->familieRolle === FamilienRolle::Zahler) {
                $geaendert += (int) $this->setzen($person, $familieId, FamilienRolle::Elternteil)->neueVersion;
            }
        }

        return $geaendert + (int) $this->setzen($neu, $familieId, FamilienRolle::Zahler)->neueVersion;
    }

    /**
     * "Anschrift übernehmen": street, postcode, town and country of the
     * source person for each of the chosen persons of the family - each one
     * a new version, like saving the form. Persons that already have the
     * address stay untouched. One audit entry with ids only (CLAUDE.md
     * section 4), when anything changed.
     *
     * @param list<int> $ids the persons that get the address
     * @throws \InvalidArgumentException the source is not in this family
     */
    public function anschriftUebernehmen(int $familieId, int $quelleId, array $ids, ?string $ip = null): AnschriftUebernahme
    {
        $personen = $this->mitglieder->derFamilie($familieId);
        $quelle = self::person($personen, $quelleId);
        $anschrift = Wohnanschrift::von($quelle);

        $geaendert = [];
        $konflikte = 0;
        foreach ($personen as $person) {
            if ($person->id === $quelle->id || !in_array($person->id, $ids, true) || Wohnanschrift::von($person)->gleich($anschrift)) {
                continue;
            }
            try {
                if ($this->speichern($person->mit($anschrift->felder()))->neueVersion) {
                    $geaendert[] = (int) $person->id;
                }
            } catch (VersionKonfliktException) {
                $konflikte++;
            }
        }

        if ($geaendert !== []) {
            $this->audit->protokollieren(
                AuditAktion::AnschriftUebernommen,
                $this->userId,
                'familie',
                $familieId,
                ['quelle' => (int) $quelle->id, 'mitglieder' => $geaendert],
                $ip,
            );
        }

        return new AnschriftUebernahme($geaendert, $konflikte);
    }

    private function speichern(Mitglied $mitglied): SpeicherErgebnis
    {
        return $this->mitglieder->speichern($mitglied, VersionsAnlass::geaendert(), $this->userId);
    }

    /**
     * @param list<Mitglied> $personen
     */
    private static function person(array $personen, int $id): Mitglied
    {
        foreach ($personen as $person) {
            if ($person->id === $id) {
                return $person;
            }
        }

        throw new \InvalidArgumentException('Not a person of this family.');
    }
}
