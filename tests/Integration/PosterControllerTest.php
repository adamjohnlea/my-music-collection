<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controllers\PosterController;
use PHPUnit\Framework\TestCase;

final class PosterControllerTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/poster_ctrl_' . bin2hex(random_bytes(4));
        mkdir($this->baseDir . '/var/posters', 0777, true);
        file_put_contents($this->baseDir . '/var/posters/poster-x.jpg', 'JPGDATA');
    }

    protected function tearDown(): void
    {
        @unlink($this->baseDir . '/var/posters/poster-x.jpg');
        @rmdir($this->baseDir . '/var/posters');
        @rmdir($this->baseDir . '/var');
        @rmdir($this->baseDir);
    }

    public function testResolvesExistingFile(): void
    {
        $r = (new PosterController())->download('poster-x.jpg', $this->baseDir);
        $this->assertSame(200, $r['status']);
        $this->assertSame($this->baseDir . '/var/posters/poster-x.jpg', $r['path']);
    }

    public function testRejectsTraversal(): void
    {
        $r = (new PosterController())->download('../../etc/passwd', $this->baseDir);
        $this->assertSame(400, $r['status']);
        $this->assertNull($r['path']);
    }

    public function testMissingFileIs404(): void
    {
        $r = (new PosterController())->download('nope.jpg', $this->baseDir);
        $this->assertSame(404, $r['status']);
    }
}
