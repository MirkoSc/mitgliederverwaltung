<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * libsodium secretbox under the server key of shared/config.php
 * (docs/spec/02-sicherheit.md section 2). Format: base64(nonce || box).
 */
final readonly class ServerKeyGeheimnisSchutz implements GeheimnisSchutz
{
    public function __construct(#[\SensitiveParameter] private string $serverKey)
    {
    }

    public function schuetzen(#[\SensitiveParameter] string $klartext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($klartext, $nonce, $this->serverKey));
    }

    public function oeffnen(string $geschuetzt): string
    {
        $roh = base64_decode($geschuetzt, true);
        if ($roh === false || strlen($roh) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new MailException('Das gespeicherte Passwort ist nicht lesbar.');
        }
        $klartext = sodium_crypto_secretbox_open(
            substr($roh, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($roh, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->serverKey,
        );
        if ($klartext === false) {
            throw new MailException('Das gespeicherte Passwort ist nicht lesbar.');
        }

        return $klartext;
    }
}
