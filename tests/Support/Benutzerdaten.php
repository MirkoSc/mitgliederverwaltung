<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\BenutzerStatus;
use App\Domain\MfaMethode;
use App\Domain\Rolle;
use App\Repository\BenutzerRepository;
use App\Service\Account\PasswordHasher;

/**
 * Made-up accounts for the tests of the login and the user administration
 * (docs/spec/05-entwicklung.md section 2: test data only invented).
 */
final class Benutzerdaten
{
    public const string PASSWORT = 'gute-pferde-springen-hoch';

    public static function anlegen(
        \PDO $pdo,
        string $email = 'erika@example.org',
        Rolle $rolle = Rolle::Vorstand,
        BenutzerStatus $status = BenutzerStatus::Aktiv,
        string $passwort = self::PASSWORT,
        string $name = 'Erika Beispiel',
        // Most accounts have their second factor; null = still to set up.
        ?MfaMethode $mfa = MfaMethode::Email,
    ): int {
        $repo = new BenutzerRepository($pdo);
        $id = $repo->insert(
            $email,
            $name,
            $status === BenutzerStatus::Eingeladen ? '' : new PasswordHasher()->hash($passwort),
            $status,
            $rolle,
            new \DateTimeImmutable(),
        );
        $repo->updateMfaMethode($id, $mfa);

        return $id;
    }
}
