<?php
/**
 * Find active products with missing/blank/broken images, resolve an image
 * (local orphan match → Openverse CC → Wikimedia → Bing), save under
 * images/products/{sku}/, and update products.image_url + product_images.
 *
 * Usage (from project root, on Hostinger or local with DB access):
 *   php tools/sync_missing_product_images.php [--dry-run] [--force] [--limit=N]
 *       [--sku=SC-001] [--sku=PB-005] [--min-score=0.35] [--delay-ms=800]
 *       [--sources=local,openverse,wikimedia,bing] [--log=storage/logs/image_sync.log]
 *
 * Exit codes: 0 ok, 1 usage/config error, 2 partial failures.
 *
 * Licensing: prefers local files already on the site, then Creative Commons
 * (Openverse / Wikimedia). Bing is a last-resort thumbnail source — review
 * those results before commercial reuse; the log records source per product.
 */

declare(strict_types=1);

$root = is_file(__DIR__ . '/lib/db.php') ? __DIR__ : dirname(__DIR__);
require $root . '/lib/db.php';
require_once $root . '/lib/products.php';

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------
$opts = [
  'dry_run' => false,
  'force' => false,
  'limit' => 0,
  'skus' => [],
  'min_score' => 0.35,
  'delay_ms' => 800,
  'sources' => ['local', 'openverse', 'wikimedia', 'bing'],
  'log' => $root . '/storage/logs/image_sync.log',
  'max_bytes' => 8 * 1024 * 1024,
  'user_agent' => 'PSK-Crackers-ImageSync/1.0 (+https://sivakasipskcrackers.store; catalog maintenance)',
];

foreach (array_slice($argv, 1) as $arg) {
  if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
  if ($arg === '--force') { $opts['force'] = true; continue; }
  if ($arg === '--help' || $arg === '-h') {
    echo "Usage: php tools/sync_missing_product_images.php [options]\n";
    echo "  --dry-run          Report only; no DB/file writes\n";
    echo "  --force            Replace existing good images\n";
    echo "  --limit=N          Process at most N products\n";
    echo "  --sku=CODE         Filter to SKU (repeatable)\n";
    echo "  --min-score=0.35   Local filename match threshold (0-1)\n";
    echo "  --delay-ms=800     Pause between remote searches\n";
    echo "  --sources=a,b,c    local,openverse,wikimedia,bing\n";
    echo "  --log=PATH         Log file path\n";
    exit(0);
  }
  if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $opts['limit'] = (int)$m[1]; continue; }
  if (preg_match('/^--sku=(.+)$/', $arg, $m)) { $opts['skus'][] = trim($m[1]); continue; }
  if (preg_match('/^--min-score=([0-9.]+)$/', $arg, $m)) { $opts['min_score'] = (float)$m[1]; continue; }
  if (preg_match('/^--delay-ms=(\d+)$/', $arg, $m)) { $opts['delay_ms'] = (int)$m[1]; continue; }
  if (preg_match('/^--sources=(.+)$/', $arg, $m)) {
    $opts['sources'] = array_values(array_filter(array_map('trim', explode(',', strtolower($m[1])))));
    continue;
  }
  if (preg_match('/^--log=(.+)$/', $arg, $m)) { $opts['log'] = $m[1]; continue; }
  fwrite(STDERR, "Unknown option: {$arg}\n");
  exit(1);
}

@mkdir(dirname($opts['log']), 0775, true);

function slog(string $msg, array $opts): void {
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
  fwrite(STDOUT, $line);
  @file_put_contents($opts['log'], $line, FILE_APPEND);
}

function normalize_tokens(string $s): array {
  $s = strtoupper($s);
  $s = str_replace(['"', "'", '×', 'X'], ['', '', ' ', ' '], $s);
  $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? '';
  $parts = preg_split('/\s+/', trim($s)) ?: [];
  $stop = ['PCS', 'PC', 'PKT', 'BOX', 'THE', 'AND', 'OF', 'IN', 'WITH', 'FOR'];
  $out = [];
  foreach ($parts as $p) {
    if ($p === '' || in_array($p, $stop, true)) continue;
    if (strlen($p) === 1 && !ctype_digit($p)) continue;
    $out[] = $p;
  }
  return $out;
}

function token_score(array $a, array $b): float {
  if (!$a || !$b) return 0.0;
  $sa = array_unique($a);
  $sb = array_unique($b);
  $inter = count(array_intersect($sa, $sb));
  if ($inter === 0) return 0.0;
  $union = count(array_unique(array_merge($sa, $sb)));
  $jaccard = $union > 0 ? $inter / $union : 0.0;
  $coverage = $inter / max(1, count($sa));
  return max($jaccard, $coverage * 0.85);
}

function is_placeholder_url(?string $url): bool {
  $u = trim((string)$url);
  if ($u === '') return true;
  return (bool)preg_match('/placeholder|via\.placeholder|placehold\.co|dummyimage|picsum\.photos/i', $u);
}

function local_path_exists(string $root, string $rel): bool {
  $rel = ltrim(str_replace('\\', '/', $rel), '/');
  if ($rel === '' || preg_match('#^https?://#i', $rel)) return false;
  if (is_file($root . '/' . $rel)) return true;
  // filenames that start with a space (historical uploads)
  $alt = preg_replace('#/ +#', '/', $rel);
  if ($alt && is_file($root . '/' . $alt)) return true;
  $base = basename($rel);
  $dir = dirname($rel);
  if ($base !== '' && is_file($root . '/' . $dir . '/ ' . $base)) return true;
  return false;
}

function http_get(string $url, array $opts, int $timeout = 25): ?string {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_CONNECTTIMEOUT => 12,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_USERAGENT => $opts['user_agent'],
      CURLOPT_HTTPHEADER => [
        'Accept: application/json,image/*,*/*;q=0.8',
      ],
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return null;
    return (string)$body;
  }
  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => "User-Agent: {$opts['user_agent']}\r\nAccept: */*\r\n",
      'timeout' => $timeout,
      'follow_location' => 1,
    ],
  ]);
  $body = @file_get_contents($url, false, $ctx);
  return $body === false ? null : (string)$body;
}

function detect_image_ext(string $bytes, ?string $hintUrl = null): ?string {
  if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
  if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) return 'png';
  if (strncmp($bytes, "GIF87a", 6) === 0 || strncmp($bytes, "GIF89a", 6) === 0) return 'gif';
  if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') return 'webp';
  if ($hintUrl) {
    $path = parse_url($hintUrl, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
      return $ext === 'jpeg' ? 'jpg' : $ext;
    }
  }
  return null;
}

function safe_sku_dir(string $sku): string {
  $s = preg_replace('/[^A-Za-z0-9_-]/', '-', $sku) ?? 'product';
  $s = trim($s, '-');
  return $s !== '' ? $s : 'product';
}

function unique_dest(string $dir, string $base, string $ext): string {
  $name = $base . '.' . $ext;
  $path = $dir . '/' . $name;
  $i = 2;
  while (is_file($path)) {
    $name = $base . '-' . $i . '.' . $ext;
    $path = $dir . '/' . $name;
    $i++;
  }
  return $path;
}

function ensure_product_images_table(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    path VARCHAR(500) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product_images_product (product_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function list_orphan_image_index(string $imgRoot): array {
  $index = [];
  if (!is_dir($imgRoot)) return $index;
  $skip = ['product_images.sql', 'pkslogo.png'];
  foreach (scandir($imgRoot) ?: [] as $f) {
    if ($f === '.' || $f === '..') continue;
    if (in_array($f, $skip, true)) continue;
    $full = $imgRoot . '/' . $f;
    if (is_dir($full)) continue; // sku dirs handled separately
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
    $stem = pathinfo($f, PATHINFO_FILENAME);
    // strip leading catalog numbers like "38 - " or "081"
    $stemClean = preg_replace('/^\s*\d+\s*[-_.]?\s*/', '', $stem) ?? $stem;
    $index[] = [
      'file' => $f,
      'rel' => 'images/products/' . $f,
      'tokens' => normalize_tokens($stemClean . ' ' . $stem),
    ];
  }
  return $index;
}

function find_local_match(string $name, string $category, array $index, float $minScore): ?array {
  $need = normalize_tokens($name . ' ' . $category);
  $nameOnly = normalize_tokens($name);
  $best = null;
  $bestScore = 0.0;
  foreach ($index as $item) {
    $score = token_score($nameOnly, $item['tokens']);
    if ($score < $minScore) {
      $score2 = token_score($need, $item['tokens']);
      $score = max($score, $score2 * 0.9);
    }
    // boost if product name tokens are almost all present
    $coverage = 0.0;
    if ($nameOnly) {
      $hit = count(array_intersect($nameOnly, $item['tokens']));
      $coverage = $hit / count($nameOnly);
    }
    $score = max($score, $coverage);
    if ($score > $bestScore) {
      $bestScore = $score;
      $best = $item + ['score' => $score];
    }
  }
  if ($best && $bestScore >= $minScore) return $best;
  return null;
}

function search_openverse(string $query, array $opts): array {
  $q = rawurlencode(mb_substr($query, 0, 180));
  $url = "https://api.openverse.org/v1/images/?q={$q}&page_size=8&license_type=commercial&mature=false";
  $body = http_get($url, $opts);
  if ($body === null) return [];
  $json = json_decode($body, true);
  if (!is_array($json) || empty($json['results']) || !is_array($json['results'])) return [];
  $out = [];
  foreach ($json['results'] as $r) {
    $u = (string)($r['url'] ?? $r['thumbnail'] ?? '');
    if ($u === '') continue;
    $out[] = ['url' => $u, 'source' => 'openverse', 'title' => (string)($r['title'] ?? ''), 'license' => (string)($r['license'] ?? '')];
  }
  return $out;
}

function search_wikimedia(string $query, array $opts): array {
  $q = rawurlencode($query);
  $api = "https://commons.wikimedia.org/w/api.php?action=query&format=json&generator=search&gsrsearch=" . $q
    . "&gsrlimit=8&gsrnamespace=6&prop=imageinfo&iiprop=url|mime|size&iiurlwidth=800";
  $body = http_get($api, $opts);
  if ($body === null) return [];
  $json = json_decode($body, true);
  $pages = $json['query']['pages'] ?? null;
  if (!is_array($pages)) return [];
  $out = [];
  foreach ($pages as $p) {
    $info = $p['imageinfo'][0] ?? null;
    if (!$info) continue;
    $mime = (string)($info['mime'] ?? '');
    if ($mime !== '' && strpos($mime, 'image/') !== 0) continue;
    $u = (string)($info['thumburl'] ?? $info['url'] ?? '');
    if ($u === '') continue;
    $out[] = ['url' => $u, 'source' => 'wikimedia', 'title' => (string)($p['title'] ?? ''), 'license' => 'commons'];
  }
  return $out;
}

/**
 * Bing image scrape — last resort. Results are often thumbnails; licensing
 * is unclear. Prefer reviewing before commercial use.
 */
function search_bing(string $query, array $opts): array {
  $q = rawurlencode($query);
  $url = "https://www.bing.com/images/search?q={$q}&form=HDRSC2&first=1";
  $html = http_get($url, $opts);
  if ($html === null || $html === '') return [];
  $out = [];
  if (preg_match_all('/murl&quot;:&quot;(https?:\\\\\/\\\\\/[^&]+?)&quot;/', $html, $m)) {
    foreach ($m[1] as $raw) {
      $u = html_entity_decode(stripslashes(str_replace('\\/', '/', $raw)));
      $u = str_replace('\\', '', $u);
      if (!preg_match('#^https?://#i', $u)) continue;
      $out[] = ['url' => $u, 'source' => 'bing', 'title' => '', 'license' => 'unknown'];
      if (count($out) >= 8) break;
    }
  }
  if (!$out && preg_match_all('/"murl":"(https?:\\\\\/\\\\\/[^"]+)"/', $html, $m2)) {
    foreach ($m2[1] as $raw) {
      $u = stripslashes(str_replace('\\/', '/', $raw));
      if (!preg_match('#^https?://#i', $u)) continue;
      $out[] = ['url' => $u, 'source' => 'bing', 'title' => '', 'license' => 'unknown'];
      if (count($out) >= 8) break;
    }
  }
  // mediaurl fallback
  if (!$out && preg_match_all('/src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i', $html, $m3)) {
    foreach ($m3[1] as $u) {
      if (stripos($u, 'bing.com') !== false || stripos($u, 'microsoft') !== false) continue;
      $out[] = ['url' => $u, 'source' => 'bing', 'title' => '', 'license' => 'unknown'];
      if (count($out) >= 6) break;
    }
  }
  return $out;
}

function category_search_hints(string $category, string $name): array {
  $cat = strtoupper(trim($category));
  $hints = [];
  $hints[] = $name . ' sivakasi crackers fireworks';
  $hints[] = $name . ' fireworks cracker product';
  $map = [
    'SINGLE CRACKERS' => 'lakshmi crackers fireworks packet india',
    'PAPER BOMB' => 'paper bomb fireworks cracker india',
    'GROUND CHAKKAR' => 'ground chakkar spinner fireworks india',
    'FLOWERPOTS' => 'flower pot fountain fireworks cracker india',
    'FANCY SHOWER ITEMS' => 'fountain fireworks cracker india diwali',
    'RAIN PEARL' => 'fountain cone fireworks cracker',
    'COLOR MATCHES' => 'colour matches fireworks cracker',
    "CHILDREN'S ITEMS" => 'kids fireworks toy cracker india',
    'SKY SHOT' => 'sky shot aerial fireworks cake',
    'CRACKLING & MULTICOLOR SKY SHOT' => 'multicolor sky shot fireworks cake',
    'SOLO PERFORMANCE FANCY SKY SHOT' => 'fancy sky shot shell fireworks',
    'EXPLOSIVE BOMB' => 'atom bomb fireworks cracker india',
  ];
  if (isset($map[$cat])) $hints[] = $map[$cat];
  $hints[] = 'diwali fireworks crackers india';
  return $hints;
}

function download_valid_image(string $url, array $opts): ?array {
  $bytes = http_get($url, $opts, 40);
  if ($bytes === null || $bytes === '') return null;
  $len = strlen($bytes);
  if ($len < 1024) return null; // too small / error page
  if ($len > $opts['max_bytes']) return null;
  $ext = detect_image_ext($bytes, $url);
  if ($ext === null) return null;
  return ['bytes' => $bytes, 'ext' => $ext, 'bytes_len' => $len];
}

function fresh_pdo(): PDO {
  // Always open a new connection (get_pdo caches a possibly-dead handle).
  $root = is_file(__DIR__ . '/lib/db.php') ? __DIR__ : dirname(__DIR__);
  $config = require $root . '/config.php';
  $db = $config['db'];
  // Optional override for local agents talking to Hostinger remote MySQL
  $host = getenv('PSK_DB_HOST') ?: ($db['host'] ?? 'localhost');
  $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $db['port'], $db['name'], $db['charset']);
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  try { $pdo->exec('SET SESSION wait_timeout=600'); } catch (Throwable $__) {}
  return $pdo;
}

function attach_image(PDO &$pdo, array $product, string $relPath, bool $dryRun, bool $force): void {
  $pid = (int)$product['id'];
  if ($dryRun) return;
  // Always refresh before write — downloads can exceed MySQL wait_timeout
  $pdo = fresh_pdo();
  $upd = $pdo->prepare('UPDATE products SET image_url = ? WHERE id = ?');
  $upd->execute([$relPath, $pid]);

  $hasPrimary = (int)$pdo->query('SELECT COUNT(*) FROM product_images WHERE product_id=' . $pid . ' AND is_primary=1')->fetchColumn();
  if ($force && $hasPrimary) {
    $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$pid]);
    $hasPrimary = 0;
  }
  $ins = $pdo->prepare('INSERT INTO product_images (product_id, path, is_primary) VALUES (?,?,?)');
  $ins->execute([$pid, $relPath, $hasPrimary ? 0 : 1]);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
try {
  $pdo = getenv('PSK_DB_HOST') ? fresh_pdo() : get_pdo();
} catch (Throwable $e) {
  fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
  exit(1);
}

ensure_products_catalog_schema($pdo);
ensure_product_images_table($pdo);

$imgRoot = $root . '/images/products';
@mkdir($imgRoot, 0775, true);

$sql = "SELECT p.id, p.sku, p.name, p.category, p.image_url,
  (SELECT path FROM product_images pi WHERE pi.product_id=p.id AND pi.is_primary=1 ORDER BY pi.id LIMIT 1) AS primary_image
FROM products p
WHERE COALESCE(p.is_active,1)=1";
$params = [];
if ($opts['skus']) {
  $place = implode(',', array_fill(0, count($opts['skus']), '?'));
  $sql .= " AND p.sku IN ($place)";
  $params = $opts['skus'];
}
$sql .= ' ORDER BY p.name';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$candidates = [];
foreach ($rows as $r) {
  $url = trim((string)($r['image_url'] ?? ''));
  $prim = trim((string)($r['primary_image'] ?? ''));
  $effective = $url !== '' ? $url : $prim;
  $hasGoodDb = false;
  if (!is_placeholder_url($effective)) {
    if (preg_match('#^https?://#i', $effective)) {
      $hasGoodDb = true;
    } else {
      $hasGoodDb = local_path_exists($root, $effective);
    }
  }
  if ($hasGoodDb && !$opts['force']) continue;

  // Empty/broken DB image but files already under images/products/{sku}/ → still a candidate
  // so we attach image_url (sku-dir step). Otherwise search/download.
  $candidates[] = $r;
}

if ($opts['limit'] > 0) {
  $candidates = array_slice($candidates, 0, $opts['limit']);
}

slog('Missing/broken image products: ' . count($candidates) . ($opts['dry_run'] ? ' (dry-run)' : ''), $opts);

$orphanIndex = in_array('local', $opts['sources'], true) ? list_orphan_image_index($imgRoot) : [];
// Avoid reusing a flat file for two products in one run
$usedOrphans = [];

$stats = ['updated' => 0, 'skipped' => 0, 'failed' => 0, 'local' => 0, 'remote' => 0];

foreach ($candidates as $product) {
  $sku = (string)$product['sku'];
  $name = (string)$product['name'];
  $category = (string)($product['category'] ?? '');
  slog("Processing {$sku} — {$name}", $opts);

  $resolved = null; // ['rel'=>..., 'source'=>..., 'note'=>...]

  // 0) Already-downloaded file under images/products/{sku}/ (e.g. prior partial run)
  if (!$resolved && in_array('local', $opts['sources'], true)) {
    $skuDir = $imgRoot . '/' . safe_sku_dir($sku);
    if (is_dir($skuDir)) {
      foreach (scandir($skuDir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
        $resolved = [
          'rel' => 'images/products/' . safe_sku_dir($sku) . '/' . $f,
          'source' => 'sku-dir',
          'score' => 1.0,
        ];
        $stats['local']++;
        break;
      }
    }
  }

  // 1) Local orphan filename match
  if (!$resolved && in_array('local', $opts['sources'], true)) {
    $available = array_values(array_filter($orphanIndex, fn($i) => !isset($usedOrphans[$i['rel']])));
    $match = find_local_match($name, $category, $available, $opts['min_score']);
    if ($match) {
      // Prefer copying into sku folder for consistency with inventory uploads
      $srcAbs = $root . '/' . $match['rel'];
      // handle leading-space filenames
      if (!is_file($srcAbs)) {
        $try = $imgRoot . '/ ' . basename($match['rel']);
        if (is_file($try)) $srcAbs = $try;
      }
      if (is_file($srcAbs)) {
        $ext = strtolower(pathinfo($srcAbs, PATHINFO_EXTENSION));
        $dir = $imgRoot . '/' . safe_sku_dir($sku);
        $base = safe_sku_dir($sku) . '_primary';
        if ($opts['dry_run']) {
          $resolved = ['rel' => 'images/products/' . safe_sku_dir($sku) . '/' . $base . '.' . $ext, 'source' => 'local:' . $match['file'], 'score' => $match['score']];
        } else {
          if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            slog("  FAIL mkdir {$dir}", $opts);
          } else {
            $dest = unique_dest($dir, $base, $ext);
            if (!@copy($srcAbs, $dest)) {
              // fall back to linking the existing flat path
              $relFlat = 'images/products/' . basename($srcAbs);
              // if basename lost leading space, store path that exists
              if (!is_file($root . '/' . $relFlat) && is_file($srcAbs)) {
                $relFlat = 'images/products/' . ltrim(basename($srcAbs));
                // keep exact basename from realpath
                $relFlat = 'images/products/' . basename($srcAbs);
              }
              $resolved = ['rel' => 'images/products/' . basename($srcAbs), 'source' => 'local-link:' . basename($srcAbs), 'score' => $match['score']];
            } else {
              $resolved = [
                'rel' => 'images/products/' . safe_sku_dir($sku) . '/' . basename($dest),
                'source' => 'local:' . $match['file'],
                'score' => $match['score'],
              ];
            }
          }
        }
        if ($resolved) {
          $usedOrphans[$match['rel']] = true;
          $stats['local']++;
        }
      }
    }
  }

  // 2) Remote search + download
  if (!$resolved) {
    $queries = category_search_hints($category, $name);
    $triedUrls = [];
    foreach ($queries as $qi => $query) {
      $results = [];
      foreach (['openverse', 'wikimedia', 'bing'] as $src) {
        if (!in_array($src, $opts['sources'], true)) continue;
        if ($opts['delay_ms'] > 0 && $qi > 0) usleep($opts['delay_ms'] * 1000);
        try {
          if ($src === 'openverse') $results = array_merge($results, search_openverse($query, $opts));
          if ($src === 'wikimedia') $results = array_merge($results, search_wikimedia($query, $opts));
          if ($src === 'bing') $results = array_merge($results, search_bing($query, $opts));
        } catch (Throwable $e) {
          slog("  search {$src} error: " . $e->getMessage(), $opts);
        }
      }
      foreach ($results as $hit) {
        $url = $hit['url'];
        if (isset($triedUrls[$url])) continue;
        $triedUrls[$url] = true;
        if ($opts['dry_run']) {
          $resolved = ['rel' => '(download) ' . $url, 'source' => $hit['source'], 'license' => $hit['license'] ?? ''];
          break 2;
        }
        $img = download_valid_image($url, $opts);
        if (!$img) continue;
        $dir = $imgRoot . '/' . safe_sku_dir($sku);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
          slog("  FAIL mkdir {$dir}", $opts);
          continue;
        }
        $dest = unique_dest($dir, safe_sku_dir($sku) . '_primary', $img['ext']);
        if (@file_put_contents($dest, $img['bytes']) === false) {
          slog("  FAIL write {$dest}", $opts);
          continue;
        }
        $resolved = [
          'rel' => 'images/products/' . safe_sku_dir($sku) . '/' . basename($dest),
          'source' => $hit['source'],
          'license' => $hit['license'] ?? '',
          'from' => $url,
        ];
        $stats['remote']++;
        break 2;
      }
      if ($opts['delay_ms'] > 0) usleep($opts['delay_ms'] * 1000);
    }
  }

  if (!$resolved) {
    slog("  FAIL no image for {$sku}", $opts);
    $stats['failed']++;
    continue;
  }

  try {
    attach_image($pdo, $product, (string)$resolved['rel'], $opts['dry_run'], $opts['force']);
    $extra = isset($resolved['score']) ? ' score=' . round((float)$resolved['score'], 2) : '';
    $lic = isset($resolved['license']) && $resolved['license'] !== '' ? ' license=' . $resolved['license'] : '';
    slog('  OK ' . ($opts['dry_run'] ? '[dry-run] ' : '') . $resolved['rel'] . ' via ' . $resolved['source'] . $extra . $lic, $opts);
    $stats['updated']++;
  } catch (Throwable $e) {
    // one retry with a fresh connection
    try {
      $pdo = fresh_pdo();
      attach_image($pdo, $product, (string)$resolved['rel'], $opts['dry_run'], $opts['force']);
      slog('  OK (retry) ' . $resolved['rel'] . ' via ' . $resolved['source'], $opts);
      $stats['updated']++;
    } catch (Throwable $e2) {
      slog('  FAIL DB: ' . $e2->getMessage(), $opts);
      $stats['failed']++;
    }
  }
}

slog(sprintf(
  'Done. updated=%d local=%d remote=%d failed=%d dry_run=%s',
  $stats['updated'],
  $stats['local'],
  $stats['remote'],
  $stats['failed'],
  $opts['dry_run'] ? 'yes' : 'no'
), $opts);

exit($stats['failed'] > 0 ? 2 : 0);
