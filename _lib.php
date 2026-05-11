<?php
declare(strict_types=1);

/**
 * BeReal-archive shared helpers.
 *
 * Loads the official BeReal export JSON files and the analysis cache,
 * resolves photo paths (which embed a user ID that isn't on disk),
 * and exposes utilities used by every page.
 *
 * Data-root discovery walks up from this file until it finds a folder
 * that contains both `Photos/` and `user.json`. That makes the scripts
 * work whether they sit inside the export folder or one level beneath it.
 */

const TZ_DEFAULT = 'UTC';
const APP_NAME   = 'BeReal Archive';

function find_data_root(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cur = __DIR__;
    for ($i = 0; $i < 5; $i++) {
        if (is_dir($cur . '/Photos') && is_file($cur . '/user.json')) {
            return $cached = $cur;
        }
        $parent = dirname($cur);
        if ($parent === $cur) break;
        $cur = $parent;
    }
    // Fallback: parent of script dir; pages will render an explanatory error.
    return $cached = dirname(__DIR__);
}

function data_root(): string { return find_data_root(); }

function data_root_ok(): bool {
    $r = data_root();
    return is_dir($r . '/Photos') && is_file($r . '/user.json');
}

function load_json(string $name) {
    $path = data_root() . '/' . $name;
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function cache_data(): array {
    static $c = null;
    if ($c !== null) return $c;
    $f = data_root() . '/cache.json';
    $c = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?? []) : [];
    return $c;
}

/**
 * Photos on disk live under Photos/{kind}/{file}.webp, but the JSON
 * stores Photos/{userId}/{kind}/{file}.webp. Strip the user-id segment.
 */
function resolve_photo_path(?string $jsonPath): ?string {
    if (!$jsonPath) return null;
    $p = ltrim($jsonPath, '/');
    $p = preg_replace('#^Photos/[^/]+/(post|profile|realmoji)/#', 'Photos/$1/', $p);
    return $p;
}

function photo_url(?string $jsonPath): string {
    $rel = resolve_photo_path($jsonPath);
    if (!$rel) return '';
    return '/img.php?p=' . rawurlencode($rel);
}

function photo_meta(?string $jsonPath): ?array {
    $rel = resolve_photo_path($jsonPath);
    if (!$rel) return null;
    $c = cache_data();
    return $c[$rel] ?? null;
}

function is_dark_photo(?string $jsonPath): bool {
    $m = photo_meta($jsonPath);
    return !empty($m['dark']);
}

function face_count(?string $jsonPath): int {
    $m = photo_meta($jsonPath);
    return isset($m['faces']) ? count($m['faces']) : 0;
}

function user(): array       { return load_json('user.json')          ?? []; }
function posts(): array      { return load_json('posts.json')         ?? []; }
function memories(): array   { return load_json('memories.json')      ?? []; }
function friends(): array    { return load_json('friends.json')       ?? []; }
function comments(): array   { return load_json('comments.json')      ?? []; }
function realmojis(): array  { return load_json('realmojis.json')     ?? []; }
function blocked(): array    { return load_json('blocked-users.json') ?? []; }

/**
 * Returns the export owner's timezone if available, otherwise a sane default.
 * Validated against PHP's known timezone list so a malformed user.json can't
 * crash DateTime construction.
 */
function display_tz(): DateTimeZone {
    static $tz = null;
    if ($tz !== null) return $tz;
    $name = (string)(user()['timezone'] ?? TZ_DEFAULT);
    if (!in_array($name, DateTimeZone::listIdentifiers(), true)) {
        $name = TZ_DEFAULT;
    }
    return $tz = new DateTimeZone($name);
}

function fmt_date(?string $iso, string $fmt = 'Y-m-d H:i'): string {
    if ($iso === null || $iso === '') return '';
    try {
        $dt = new DateTime($iso);
        $dt->setTimezone(display_tz());
        return $dt->format($fmt);
    } catch (Exception $e) {
        return (string)$iso;
    }
}

function nice_num(int $n): string  { return number_format($n, 0, '.', ' '); }
function pct(float $n): string     { return number_format($n, 1) . '%'; }
function h(string $s): string      { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Build a normalized stream of posts joined with their memory entry so we
 * can show isLate / berealMoment alongside each post. Sorted oldest-first.
 *
 * `posts.primary` corresponds to `memories.backImage` (back camera), and
 * `posts.secondary` corresponds to `memories.frontImage` (selfie). We index
 * memories by *both* image paths so the join is robust to either ordering.
 */
function unified_stream(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $posts = posts();
    $mems  = memories();

    $byPath = [];
    foreach ($mems as $m) {
        foreach (['frontImage', 'backImage'] as $side) {
            $k = resolve_photo_path($m[$side]['path'] ?? null);
            if ($k) $byPath[$k] = $m;
        }
    }

    $out = [];
    foreach ($posts as $p) {
        $primary   = $p['primary']   ?? null;
        $secondary = $p['secondary'] ?? null;
        $kPrim = resolve_photo_path($primary['path']   ?? null);
        $kSec  = resolve_photo_path($secondary['path'] ?? null);
        $m = $byPath[$kPrim] ?? $byPath[$kSec] ?? null;

        $out[] = [
            'takenAt'      => $p['takenAt']      ?? ($m['takenTime'] ?? null),
            'berealMoment' => $m['berealMoment'] ?? null,
            'isLate'       => $m['isLate']       ?? null,
            'retakes'      => $p['retakeCounter'] ?? 0,
            'location'     => $p['location'] ?? null,
            'visibility'   => $p['visibility'] ?? [],
            'primary'      => $primary,
            'secondary'    => $secondary,
        ];
    }
    usort($out, fn($a, $b) => strcmp((string)$a['takenAt'], (string)$b['takenAt']));
    return $cached = $out;
}

function nav_link(string $href, string $label, string $current): string {
    $active = $current === $href ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="' . h($href) . '">' . h($label) . '</a>';
}

function render_header(string $current, string $title = ''): void {
    $u = user();
    $name = $u['fullname'] ?? ($u['username'] ?? APP_NAME);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title><?= h($title !== '' ? $title : $name . ' · ' . APP_NAME) ?></title>
      <link rel="stylesheet" href="/style.css">
    </head>
    <body>
    <header class="site">
      <div class="brand">
        <span class="dot"></span>
        <strong><?= h((string)$name) ?></strong>
        <span class="muted">· <?= h(APP_NAME) ?></span>
      </div>
      <nav>
        <?= nav_link('/index.php',    'Dashboard', $current) ?>
        <?= nav_link('/gallery.php',  'Gallery',   $current) ?>
        <?= nav_link('/people.php',   'People',    $current) ?>
        <?= nav_link('/faces.php',    'All faces', $current) ?>
        <?= nav_link('/map.php',      'Map',       $current) ?>
        <?= nav_link('/friends.php',  'Friends',   $current) ?>
        <?= nav_link('/comments.php', 'Comments',  $current) ?>
      </nav>
    </header>
    <main>
    <?php
    if (!data_root_ok()) {
        echo '<div class="card" style="border-color: var(--danger);"><strong>BeReal export not found.</strong> ';
        echo 'Place this folder inside your unzipped BeReal export (the directory that contains <code>user.json</code> and <code>Photos/</code>), ';
        echo 'then reload.</div>';
    }
}

function render_footer(): void {
    ?>
    </main>
    <footer class="site">
      <span class="muted">Local-only · generated <?= h(date('Y-m-d H:i')) ?> · <a href="https://github.com/" target="_blank" rel="noopener">bereal-archive</a></span>
    </footer>
    </body></html>
    <?php
}
