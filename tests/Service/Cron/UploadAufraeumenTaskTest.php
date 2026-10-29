<?php

declare(strict_types=1);

namespace App\Tests\Service\Cron;

use App\Service\Cron\UploadAufraeumenTask;
use App\Service\Upload\UploadService;
use PHPUnit\Framework\TestCase;

/** Interrupted uploads leave no plaintext pieces behind for more than a day. */
final class UploadAufraeumenTaskTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/upload-aufraeumen-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $datei) {
            unlink($datei);
        }
        foreach (glob($this->dir . '/*') ?: [] as $ordner) {
            rmdir($ordner);
        }
        rmdir($this->dir);
    }

    private function upload(string $id, int $alterInStunden): void
    {
        mkdir($this->dir . '/' . $id);
        file_put_contents($this->dir . '/' . $id . '/0', 'Klartext');
        $zeit = time() - $alterInStunden * 3600;
        touch($this->dir . '/' . $id . '/0', $zeit);
        touch($this->dir . '/' . $id, $zeit);
    }

    public function testOldUploadsGoRecentOnesStay(): void
    {
        $alt = str_repeat('a', 32);
        $neu = str_repeat('b', 32);
        $this->upload($alt, 30);
        $this->upload($neu, 2);
        $task = new UploadAufraeumenTask(new UploadService($this->dir));

        self::assertSame('uploads_aufraeumen', $task->name());
        self::assertSame(1, $task->run(new \DateTimeImmutable()));
        self::assertDirectoryDoesNotExist($this->dir . '/' . $alt);
        self::assertDirectoryExists($this->dir . '/' . $neu);
    }
}
