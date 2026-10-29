<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Protects a secret that has to be readable again without a user - here the
 * SMTP password - before it goes into the database.
 *
 * An interface so that issue #5 (server key and encrypted files) can put its
 * own service behind it; until then App\Service\Mail\ServerKeyGeheimnisSchutz
 * does it with the server key from config.php directly.
 */
interface GeheimnisSchutz
{
    /** Ciphertext as text (base64), safe for a JSON setting. */
    public function schuetzen(#[\SensitiveParameter] string $klartext): string;

    /**
     * @throws MailException when the value is not ours (another server key,
     *         a damaged row) - the caller treats the secret as "not set"
     */
    public function oeffnen(string $geschuetzt): string;
}
