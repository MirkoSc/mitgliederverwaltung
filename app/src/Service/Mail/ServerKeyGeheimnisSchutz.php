<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * libsodium secretbox under the server key of shared/config.php
 * (docs/spec/02-sicherheit.md section 2). Format: base64(nonce || box).
 *
 * While a key change runs, oeffnen() falls back to the previous key, like
 * App\Service\Crypto\ServerCrypto; schuetzen() only uses the current one.
 */
final readonly class ServerKeyGeheimnisSchutz implements GeheimnisSchutz
{
    public function __construct(
        #[\SensitiveParameter] private string $serverKey,
        #[\SensitiveParameter] private ?string $alterSchluessel = null,
    ) {
    }

    /** Whether the current key alone opens the value (App\Service\Crypto\Schluesselwechsel). */
    public function mitAktuellemSchluessel(string $geschuetzt): bool
    {
        return self::entschluesseln($geschuetzt, $this->serverKey) !== null;
    }

    public function schuetzen(#[\SensitiveParameter] string $klartext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($klartext, $nonce, $this->serverKey));
    }

    public function oeffnen(string $geschuetzt): string
    {
        $klartext = self::entschluesseln($geschuetzt, $this->serverKey);
        if ($klartext === null && $this->alterSchluessel !== null) {
            $klartext = self::entschluesseln($geschuetzt, $this->alterSchluessel);
        }
        if ($klartext === null) {
            throw new MailException('Das gespeicherte Passwort ist nicht lesbar.');
        }

        return $klartext;
    }

    private static function entschluesseln(string $geschuetzt, #[\SensitiveParameter] string $schluessel): ?string
    {
        $roh = base64_decode($geschuetzt, true);
        if ($roh === false || strlen($roh) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $klartext = sodium_crypto_secretbox_open(
            substr($roh, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($roh, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $schluessel,
        );

        return $klartext === false ? null : $klartext;
    }
}
