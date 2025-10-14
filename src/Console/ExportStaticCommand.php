<?php
declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[AsCommand(name: 'export:static', description: 'Generate a static site (HTML + JSON + images) for your collection')]
class ExportStaticCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory for the static site', 'dist')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL prefix for links (e.g., / or /subdir)', '/')
            ->addOption('copy-images', null, InputOption::VALUE_NONE, 'Copy cached images into the static folder')
            ->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Chunk size for releases.json (0 = single file)', '0');
    }

    private function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return $value;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $this->env('DB_PATH', 'var/app.db') ?? 'var/app.db';
        if ($dbPath[0] !== '/' && !preg_match('#^[A-Za-z]:[\\/]#', $dbPath)) {
            $dbPath = $baseDir . '/' . ltrim($dbPath, '/');
        }

        $outDir = (string)$input->getOption('out');
        if ($outDir[0] !== '/' && !preg_match('#^[A-Za-z]:[\\/]#', $outDir)) {
            $outDir = $baseDir . '/' . ltrim($outDir, '/');
        }
        $baseUrlOpt = (string)$input->getOption('base-url');
        $baseUrlOpt = trim($baseUrlOpt);
        // Treat empty, "." or "/" as no base URL (relative paths)
        $noBaseUrl = ($baseUrlOpt === '' || $baseUrlOpt === '.' || $baseUrlOpt === '/');
        $baseUrl = $noBaseUrl ? '' : rtrim($baseUrlOpt, '/');
        // Copy images by default if public/images exists; the --copy-images flag also forces copying
        $copyImages = (bool)$input->getOption('copy-images') || is_dir($baseDir . '/public/images');
        $chunkSize = max(0, (int)$input->getOption('chunk-size'));

        // Init DB and migrations (non-destructive)
        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        // Resolve current username to limit collection to your items
        $username = $this->resolveUsername($pdo);
        if (!$username) {
            $output->writeln('<error>No Discogs username found. Please configure your account in the app first.</error>');
            return Command::INVALID;
        }

        // Prepare filesystem
        $this->rrmdir($outDir);
        $this->ensureDir($outDir);
        $dataDir = $outDir . '/data';
        $this->ensureDir($dataDir);
        $releasesJsonDir = $dataDir . '/releases';
        $this->ensureDir($releasesJsonDir);
        $pagesDir = $outDir . '/releases';
        $this->ensureDir($pagesDir);
        $assetsDir = $outDir . '/assets';
        $this->ensureDir($assetsDir);

        // Twig for static pages
        $twig = new Environment(new FilesystemLoader($baseDir . '/templates'));
        // Register custom Twig extensions/filters used by templates
        $twig->addExtension(new \App\Presentation\Twig\DiscogsFilters());
        $twig->addGlobal('static_export', true);
        // When no base URL, templates should rely on relative links
        $twig->addGlobal('base_url', $baseUrl);

        // 1) Export lightweight releases list JSON for catalog
        $output->writeln('<info>Exporting releases list…</info>');
        $all = $this->fetchAllReleases($pdo, $username, $baseDir, $baseUrl);
        $count = count($all);
        $output->writeln(sprintf('  - %d releases', $count));

        if ($chunkSize > 0 && $count > $chunkSize) {
            $chunks = array_chunk($all, $chunkSize);
            $manifest = [];
            foreach ($chunks as $i => $chunk) {
                $name = sprintf('releases-%d.json', $i + 1);
                file_put_contents($dataDir . '/' . $name, json_encode($chunk, JSON_UNESCAPED_SLASHES));
                $manifest[] = ['file' => $name, 'count' => count($chunk)];
            }
            file_put_contents($dataDir . '/releases.manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
        } else {
            file_put_contents($dataDir . '/releases.json', json_encode($all, JSON_UNESCAPED_SLASHES));
        }

        // 2) Export per-release JSON and HTML pages
        $output->writeln('<info>Exporting release detail pages…</info>');
        $n = 0;
        foreach ($all as $row) {
            $rid = (int)$row['id'];
            [$release, $details, $images, $imageUrl] = $this->loadReleaseDetail($pdo, $rid, $baseDir, $baseUrl);
            // JSON
            file_put_contents($releasesJsonDir . '/' . $rid . '.json', json_encode([
                'release' => $release,
                'details' => $details,
                'images' => $images,
                'image_url' => $imageUrl,
            ], JSON_UNESCAPED_SLASHES));
            // HTML
            $html = $twig->render('release.html.twig', [
                'title' => ($release['artist'] ? ($release['artist'] . ' — ') : '') . $release['title'],
                'release' => $release,
                'details' => $details,
                'images' => $images,
                'image_url' => $imageUrl,
                'back_url' => ($baseUrl === '' ? '../index.html' : ($baseUrl . '/')),
                'auth_user' => null,
                'static_export' => true,
            ]);
            file_put_contents($pagesDir . '/' . $rid . '.html', $html);
            $n++;
            if (($n % 100) === 0) $output->writeln("  - $n / $count");
        }

        // 3) Copy images (optional)
        if ($copyImages) {
            $src = $baseDir . '/public/images';
            $dst = $outDir . '/images';
            if (is_dir($src)) {
                $output->writeln('<info>Copying images…</info>');
                $this->rcopy($src, $dst);
            } else {
                $output->writeln('<comment>No local images to copy (public/images not found)</comment>');
            }
        }

        // 4) Write static assets (CSS/JS) and index.html
        $output->writeln('<info>Writing static assets and index.html…</info>');
        $this->writeClientBundle($assetsDir);

        $indexHtml = $twig->render('static/index.html.twig', [
            'title' => 'My Music Collection',
            'total' => $count,
            'base_url' => $baseUrl,
            'data_is_chunked' => $chunkSize > 0 && $count > $chunkSize,
            // Provide inline JSON as a fallback when opening via file:// where fetch() may be blocked
            'releases_json' => ($chunkSize > 0 && $count > $chunkSize) ? null : json_encode($all, JSON_UNESCAPED_SLASHES),
        ]);
        file_put_contents($outDir . '/index.html', $indexHtml);

        $output->writeln('<info>Static export complete.</info>');
        $output->writeln('Open the dist/index.html in a browser, or upload the dist/ folder to any static host.');
        return Command::SUCCESS;
    }

    private function resolveUsername(\PDO $pdo): ?string
    {
        try {
            // kv_store uses columns `k` and `v`
            $st = $pdo->query("SELECT v FROM kv_store WHERE k = 'current_user_id' LIMIT 1");
            $uid = (int)($st->fetchColumn() ?: 0);
            if ($uid <= 0) return null;
            $st2 = $pdo->prepare('SELECT discogs_username FROM auth_users WHERE id = :id');
            $st2->execute([':id' => $uid]);
            $u = $st2->fetchColumn();
            return $u ? (string)$u : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fetchAllReleases(\PDO $pdo, string $username, string $baseDir, string $baseUrl): array
    {
        $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
            (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
            (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
            (SELECT MAX(ci2.added) FROM collection_items ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS added_at,
            (SELECT MAX(ci3.rating) FROM collection_items ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS rating
        FROM releases r
        WHERE EXISTS (SELECT 1 FROM collection_items ci WHERE ci.release_id = r.id AND ci.username = :u)
        GROUP BY r.id
        ORDER BY r.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute([':u' => $username]);
        $rows = $st->fetchAll();
        // decorate image url
        foreach ($rows as &$row) {
            $img = $this->pickImageUrl($row['primary_local_path'] ?? null, $row['any_local_path'] ?? null, $row['cover_url'] ?? null, $row['thumb_url'] ?? null, $baseDir, $baseUrl);
            $row['image'] = $img;
            unset($row['primary_local_path'], $row['any_local_path']);
        }
        return $rows;
    }

    private function loadReleaseDetail(\PDO $pdo, int $rid, string $baseDir, string $baseUrl): array
    {
        $stmt = $pdo->prepare("SELECT r.* FROM releases r WHERE r.id = :id");
        $stmt->execute([':id' => $rid]);
        $release = $stmt->fetch() ?: null;

        $imageUrl = null;
        $images = [];
        $details = [
            'labels' => [],
            'formats' => [],
            'genres' => [],
            'styles' => [],
            'tracklist' => [],
            'videos' => [],
            'extraartists' => [],
            'companies' => [],
            'identifiers' => [],
            'notes' => null,
            'user_notes' => null,
            'user_rating' => null,
            'barcodes' => [],
            'other_identifiers' => [],
        ];

        if ($release) {
            $imgStmt = $pdo->prepare('SELECT source_url, local_path FROM images WHERE release_id = :rid ORDER BY id ASC');
            $imgStmt->execute([':rid' => $rid]);
            $rows = $imgStmt->fetchAll();
            $primaryUrl = $release['cover_url'] ?: ($release['thumb_url'] ?? null);
            foreach ($rows as $row) {
                $local = $row['local_path'] ?? null;
                $url = null;
                if ($local) {
                    $abs = $baseDir . '/' . ltrim($local, '/');
                    if (is_file($abs)) {
                        $publicPath = ltrim(preg_replace('#^public/#','', $local), '/');
                        $url = ($baseUrl === '') ? ('../' . $publicPath) : (rtrim($baseUrl, '/') . '/' . $publicPath);
                    }
                }
                if (!$url) {
                    $url = $row['source_url'];
                }
                $images[] = [
                    'url' => $url,
                    'source_url' => $row['source_url'],
                    'is_primary' => ($primaryUrl && $row['source_url'] === $primaryUrl),
                ];
            }
            foreach ($images as $img) { if ($img['is_primary']) { $imageUrl = $img['url']; break; } }
            if (!$imageUrl && !empty($images)) $imageUrl = $images[0]['url'];
            if (!$imageUrl) $imageUrl = $release['cover_url'] ?: ($release['thumb_url'] ?? null);

            $details['labels'] = json_decode((string)($release['labels'] ?? '[]'), true) ?: [];
            $details['formats'] = json_decode((string)($release['formats'] ?? '[]'), true) ?: [];
            $details['genres'] = json_decode((string)($release['genres'] ?? '[]'), true) ?: [];
            $details['styles'] = json_decode((string)($release['styles'] ?? '[]'), true) ?: [];
            $details['tracklist'] = json_decode((string)($release['tracklist'] ?? '[]'), true) ?: [];
            $details['videos'] = json_decode((string)($release['videos'] ?? '[]'), true) ?: [];
            $details['extraartists'] = json_decode((string)($release['extraartists'] ?? '[]'), true) ?: [];
            $details['companies'] = json_decode((string)($release['companies'] ?? '[]'), true) ?: [];
            $details['identifiers'] = json_decode((string)($release['identifiers'] ?? '[]'), true) ?: [];
            $details['notes'] = $release['release_notes'] ?? null;
            $details['user_notes'] = $release['user_notes'] ?? null;
            $details['user_rating'] = $release['user_rating'] ?? null;
            // explode identifiers to specific arrays used by the template
            $details['barcodes'] = array_values(array_filter(($details['identifiers'] ?? []), fn($i) => isset($i['type']) && stripos($i['type'], 'barcode') !== false));
            $details['other_identifiers'] = array_values(array_filter(($details['identifiers'] ?? []), fn($i) => !isset($i['type']) || stripos($i['type'], 'barcode') === false));
        }

        return [$release, $details, $images, $imageUrl];
    }

    private function pickImageUrl(?string $primaryLocal, ?string $anyLocal, ?string $coverUrl, ?string $thumbUrl, string $baseDir, string $baseUrl, int $depth = 0): ?string
    {
        $local = $primaryLocal ?: $anyLocal;
        if ($local) {
            $abs = $baseDir . '/' . ltrim($local, '/');
            if (is_file($abs)) {
                // Strip public/ from local path
                $publicPath = ltrim(preg_replace('#^public/#','', $local), '/');
                if ($baseUrl === '') {
                    $prefix = $depth > 0 ? str_repeat('../', $depth) : '';
                    return $prefix . $publicPath;
                }
                return rtrim($baseUrl, '/') . '/' . $publicPath;
            }
        }
        return $coverUrl ?: $thumbUrl;
    }

    private function writeClientBundle(string $assetsDir): void
    {
        $js = <<<'JS'
(function(){
  const state = { data: [], q: '', sort: 'added_desc', page: 1, perPage: 24 };
  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  const params = new URLSearchParams(location.search);
  state.q = params.get('q') || '';
  state.sort = params.get('sort') || 'added_desc';
  state.page = Math.max(1, parseInt(params.get('page')||'1',10));
  state.perPage = Math.max(1, Math.min(60, parseInt(params.get('per_page')||'24',10)));

  function cmp(a,b){
    const dir = (state.sort.endsWith('_desc') ? -1 : 1);
    const key = state.sort.replace(/_(asc|desc)$/,'');
    const kmap = {
      added: r => r.added_at || '',
      artist: r => (r.artist||'').toLowerCase(),
      title: r => (r.title||'').toLowerCase(),
      year: r => (r.year||0),
      rating: r => (r.rating==null ? -1 : r.rating),
      imported: r => r.id,
    };
    let ka = kmap[key]?kmap[key](a):a.id, kb = kmap[key]?kmap[key](b):b.id;
    if (ka<kb) return -1*dir; if (ka>kb) return 1*dir; return a.id-b.id;
  }

  function parseQuery(q){
    q = (q||'').trim().replace(/(\b\w+):\s+/g,'$1:');
    const tokens = q? q.match(/(")([^\"]*)(")|(\S+)/g) : [];
    const fields = [];
    const terms = [];
    let yearFrom=null, yearTo=null;
    const map = { artist:'artist', title:'title', label:'label_text', format:'format_text', genre:'genre_style_text', style:'genre_style_text', country:'country', credit:'credit_text', company:'company_text', identifier:'identifier_text', barcode:'identifier_text', notes:'notes' };
    (tokens||[]).forEach(tok=>{
      if (/^year:\d{4}(\.\.|$)/i.test(tok)){
        const v = tok.split(':')[1];
        if (/^\d{4}\.\.\d{4}$/.test(v)){ const [a,b]=v.split('..'); yearFrom=+a; yearTo=+b; return; }
        if (/^\d{4}$/.test(v)){ yearFrom=+v; yearTo=+v; return; }
      }
      const m = tok.match(/^(\w+):(.*)$/);
      if (m){
        const key=m[1].toLowerCase(); let val=m[2]; if (val.startsWith('"')&&val.endsWith('"')) val=val.slice(1,-1);
        if (map[key]){ fields.push({field: map[key], value: val.toLowerCase()}); return; }
      }
      terms.push(tok.replace(/\*/g,'').toLowerCase());
    });
    return {fields, terms, yearFrom, yearTo};
  }

  function applyFilters(items){
    const {fields, terms, yearFrom, yearTo} = parseQuery(state.q);
    let out = items;
    if (terms.length){
      out = out.filter(r=>{
        const hay = (r.artist+' '+r.title+' '+(r.genre_style_text||'')+' '+(r.label_text||'')+' '+(r.format_text||'')+' '+(r.country||'')).toLowerCase();
        return terms.every(t=>hay.includes(t));
      });
    }
    fields.forEach(f=>{
      out = out.filter(r=>String(r[f.field]||'').toLowerCase().includes(f.value));
    });
    if (yearFrom!=null && yearTo!=null){ out = out.filter(r=> (r.year||0)>=yearFrom && (r.year||0)<=yearTo ); }
    return out;
  }

  function paginate(items){
    const total = items.length;
    const pages = Math.max(1, Math.ceil(total/state.perPage));
    const page = Math.max(1, Math.min(state.page, pages));
    const slice = items.slice((page-1)*state.perPage, page*state.perPage);
    return {total, pages, page, slice};
  }

  function render(items){
    items.sort(cmp);
    const filtered = applyFilters(items);
    const {total, pages, page, slice} = paginate(filtered);
    $('#stats').textContent = `${total} items` + (state.q? ` • Search: “${state.q}”`:'' );
    const grid = $('#grid'); grid.innerHTML='';
    slice.forEach(r=>{
      const a = document.createElement('a'); a.className='card'; a.href = `releases/${r.id}.html`;
      a.innerHTML = `<img class="cover ready" src="${r.image||''}" alt="${(r.title||'').replaceAll('"','&quot;')}"/>\n<div class="meta"><div class="title">${r.title||''}</div><div class="artist">${r.artist||''}${r.year? ' • '+r.year:''}</div></div>`;
      grid.appendChild(a);
    });
    renderPager(pages, page);
    updateQueryString();
  }

  function renderPager(pages, page){
    const nav = $('#pager'); nav.innerHTML='';
    function btn(label, p, disabled, current){
      const a = document.createElement('a'); a.className='page-btn'+(current?' is-current':'')+(disabled?' is-disabled':'');
      if (!disabled) a.href = `?q=${encodeURIComponent(state.q)}&sort=${state.sort}&page=${p}&per_page=${state.perPage}`;
      a.textContent = label; nav.appendChild(a);
    }
    btn('Prev', Math.max(1, page-1), page<=1, false);
    const span = 2; const start=Math.max(1, page-span), end=Math.min(pages, page+span);
    for (let p=start;p<=end;p++){ btn(String(p), p, false, p===page); }
    btn('Next', Math.min(pages, page+1), page>=pages, false);
  }

  function updateQueryString(){
    const params = new URLSearchParams({ q: state.q, sort: state.sort, page: String(state.page), per_page: String(state.perPage) });
    history.replaceState(null, '', '?' + params.toString());
  }

  function wire(){
    const q = $('#search-input'); const sort = $('#sort');
    q.value = state.q; sort.value = state.sort;
    q.addEventListener('input', ()=>{ state.q=q.value; state.page=1; render(state.data.slice()); });
    sort.addEventListener('change', ()=>{ state.sort=sort.value; render(state.data.slice()); });
  }

  async function load(){
    // Try chunked manifest else single file; if both fail (e.g., file://), fall back to inline JSON
    try {
      const manRes = await fetch('data/releases.manifest.json');
      if (manRes.ok){
        const manifest = await manRes.json();
        const files = manifest.map(m=> 'data/'+m.file);
        const arrays = await Promise.all(files.map(f=> fetch(f).then(r=>r.json())));
        state.data = arrays.flat();
        return;
      }
    } catch(e) {}
    try {
      const res = await fetch('data/releases.json');
      if (res.ok){ state.data = await res.json(); return; }
    } catch(e) {}
    // Inline fallback
    const el = document.getElementById('releases-data');
    if (el) {
      try { state.data = JSON.parse(el.textContent||'[]'); } catch(e) { state.data = []; }
    } else {
      state.data = [];
    }
  }

  wire();
  load().then(()=> render(state.data.slice()));
})();
JS;
        $css = ""; // inline styles are already in the Twig template
        file_put_contents($assetsDir . '/app.js', $js);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') continue;
            $path = $dir . '/' . $i;
            if (is_dir($path)) { $this->rrmdir($path); rmdir($path); }
            else { @unlink($path); }
        }
    }

    private function rcopy(string $src, string $dst): void
    {
        $this->ensureDir($dst);
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') continue;
            $from = $src . '/' . $file;
            $to = $dst . '/' . $file;
            if (is_dir($from)) { $this->rcopy($from, $to); }
            else { copy($from, $to); }
        }
        closedir($dir);
    }
}
