<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Poster\PosterOrderer;
use App\Domain\Search\QueryParser;
use App\Images\PosterComposer;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\PosterReleaseFinder;
use Imagick;
use ImagickPixel;
use PDO;
use PHPUnit\Framework\TestCase;

final class PosterPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
    }

    private function solid(string $dir, string $name, string $color): string
    {
        $path = $dir . '/' . $name;
        $img = new Imagick();
        $img->newImage(40, 40, new ImagickPixel($color));
        $img->setImageFormat('jpeg');
        $img->writeImage($path);
        $img->clear();
        return $path;
    }

    public function testFinderOrdererComposerProduceAPoster(): void
    {
        $work = sys_get_temp_dir() . '/poster_pipe_' . bin2hex(random_bytes(4));
        mkdir($work, 0777, true);

        $cover1 = $this->solid($work, '1.jpg', 'rgb(200,0,0)');
        $cover2 = $this->solid($work, '2.jpg', 'rgb(0,0,200)');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $pdo->exec("INSERT INTO releases (id, title, artist, year, cover_url) VALUES
            (1, 'A', 'Artist A', 1990, 'http://x/1.jpg'),
            (2, 'B', 'Artist B', 1991, 'http://x/2.jpg')");
        $pdo->exec("INSERT INTO collection_items (instance_id, username, folder_id, release_id, added) VALUES
            (11, 'me', 0, 1, '2020-01-01'),
            (12, 'me', 0, 2, '2021-01-01')");
        $st = $pdo->prepare("INSERT INTO images (release_id, source_url, local_path) VALUES (:r, :s, :p)");
        $st->execute([':r' => 1, ':s' => 'http://x/1.jpg', ':p' => $cover1]);
        $st->execute([':r' => 2, ':s' => 'http://x/2.jpg', ':p' => $cover2]);

        $finder = new PosterReleaseFinder($pdo, new QueryParser());
        $rows = $finder->find('me', 'collection', null);
        $rows = (new PosterOrderer())->order($rows, 'added');

        $tiles = array_map(fn($r) => [
            'path' => $r['cover_path'],       // absolute in this test (stored full path)
            'color' => $r['cover_color'] ?? '#333333',
            'caption' => $r['artist'],
        ], $rows);

        $out = $work . '/poster.jpg';
        (new PosterComposer())->compose($tiles, [
            'cols' => 2, 'resolution' => 200, 'gap' => 0,
            'bg' => '#000000', 'format' => 'jpg', 'quality' => 90,
        ], $out);

        $this->assertFileExists($out);
        $img = new Imagick($out);
        $this->assertSame(200, $img->getImageWidth());
        $img->clear();

        array_map('unlink', glob($work . '/*') ?: []);
        rmdir($work);
    }
}
