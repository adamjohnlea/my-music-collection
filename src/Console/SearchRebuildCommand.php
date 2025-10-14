<?php
declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use App\Infrastructure\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:rebuild', description: 'Rebuild the FTS index from current releases and collection_items.')]
class SearchRebuildCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $config->getDbPath($baseDir);
        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        $output->writeln('<info>Rebuilding FTS index…</info>');
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM releases_fts');
            $pdo->exec(
                "INSERT INTO releases_fts(
                    rowid, artist, title, label_text, format_text, genre_style_text, country, track_text, credit_text, company_text, identifier_text, release_notes, user_notes
                )
                SELECT
                    r.id,
                    r.artist,
                    r.title,
                    json_extract(r.labels, '$[0].name') || ' ' || COALESCE(json_extract(r.labels, '$[0].catno'), ''),
                    (SELECT group_concat(json_extract(v.value, '$.name'), ' ')
                     FROM json_each(COALESCE(r.formats, '[]')) v),
                    (SELECT (SELECT group_concat(value, ' ') FROM json_each(COALESCE(r.genres, '[]'))) || ' ' ||
                            (SELECT group_concat(value, ' ') FROM json_each(COALESCE(r.styles, '[]')))),
                    COALESCE(r.country, ''),
                    (SELECT group_concat(json_extract(t.value, '$.title'), ' ')
                     FROM json_each(COALESCE(r.tracklist, '[]')) t),
                    (SELECT group_concat(json_extract(a.value, '$.name') || ' ' || COALESCE(json_extract(a.value, '$.role'), ''), ' ')
                     FROM json_each(COALESCE(r.extraartists, '[]')) a),
                    (SELECT group_concat(json_extract(c.value, '$.name') || ' ' || COALESCE(json_extract(c.value, '$.entity_type_name'), ''), ' ')
                     FROM json_each(COALESCE(r.companies, '[]')) c),
                    (SELECT group_concat(json_extract(i.value, '$.value'), ' ')
                     FROM json_each(COALESCE(r.identifiers, '[]')) i),
                    COALESCE(r.notes, ''),
                    (SELECT ci.notes FROM collection_items ci WHERE ci.release_id = r.id ORDER BY ci.added DESC LIMIT 1)
                FROM releases r"
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $output->writeln('<info>FTS rebuild complete.</info>');
        return Command::SUCCESS;
    }
}
