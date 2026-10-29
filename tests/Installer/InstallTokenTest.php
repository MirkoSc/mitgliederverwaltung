<?php

declare(strict_types=1);

namespace App\Tests\Installer;

use App\Installer\InstallToken;
use PHPUnit\Framework\TestCase;

/**
 * The installation token (docs/spec/04-installation-und-update.md section 2):
 * 128 bits in a 0600 file, released into the session as a hash only.
 */
final class InstallTokenTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mv_token_' . bin2hex(random_bytes(4));
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/shared/install_token.txt');
        @rmdir($this->dir . '/shared');
        @rmdir($this->dir);
        $_SESSION = [];
    }

    private function token(): InstallToken
    {
        return new InstallToken($this->dir . '/shared/install_token.txt');
    }

    public function testEnsureCreatesA128BitTokenOnceAndKeepsIt(): void
    {
        $erster = $this->token()->ensure();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $erster);
        self::assertSame($erster, $this->token()->ensure());
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/shared/install_token.txt')), -4));
    }

    public function testMatchingIgnoresSpacesAndCaseButNothingElse(): void
    {
        $token = $this->token()->ensure();

        self::assertTrue($this->token()->matches(' ' . strtoupper(substr($token, 0, 8)) . ' ' . substr($token, 8) . "\n"));
        self::assertFalse($this->token()->matches(substr($token, 0, -1)));
        self::assertFalse($this->token()->matches(''));
    }

    public function testATokenUploadedByFtpIsAccepted(): void
    {
        mkdir($this->dir . '/shared', 0775, true);
        file_put_contents($this->dir . '/shared/install_token.txt', "MeinEigenesToken2026\n");

        self::assertTrue($this->token()->matches('meineigenestoken2026'));
    }

    public function testTheSessionKeepsAHashAndLosesTheReleaseWhenTheFileChanges(): void
    {
        $token = $this->token()->ensure();
        self::assertFalse($this->token()->isReleased());

        $this->token()->release();
        self::assertTrue($this->token()->isReleased());
        self::assertNotContains($token, $_SESSION);

        file_put_contents($this->dir . '/shared/install_token.txt', bin2hex(random_bytes(16)));
        self::assertFalse($this->token()->isReleased());

        unlink($this->dir . '/shared/install_token.txt');
        self::assertFalse($this->token()->isReleased());
    }
}
