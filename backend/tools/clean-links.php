<?php
declare(strict_types=1);

// One-time tool: turns links saved in the database ("occasion.html?id=x", "contact.html") into clean addresses
// ("occasion?id=x", "contact"). Safe to run again. External links (https://...) are never touched.
//   C:\xampp\php\php.exe backend\tools\clean-links.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line, not the browser.');
}
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

/** @return string the cleaned link (or the same text if it is not a local .html link) */
function cleanLink(string $v): string
{
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $v)) {                 // https:, mailto:, tel:, //host
        return $v;
    }
    if (!preg_match('#^((?:[A-Za-z0-9_-]+/)*)([A-Za-z0-9_-]+)\.html(?=$|[?\#])(.*)$#s', $v, $m)) {
        return $v;
    }
    return $m[2] === 'index' ? ($m[1] === '' ? './' : $m[1]) . $m[3] : $m[1] . $m[2] . $m[3];
}

/** Cleans every string in a decoded JSON value whose key says it is a link. */
function cleanTree(mixed $x, int &$n): mixed
{
    if (is_array($x)) {
        foreach ($x as $k => $v) {
            if (is_string($v) && in_array((string) $k, ['href', 'url', 'link', 'link_href'], true)) {
                $c = cleanLink($v);
                if ($c !== $v) { $n++; }
                $x[$k] = $c;
            } else {
                $x[$k] = cleanTree($v, $n);
            }
        }
    }
    return $x;
}

$pdo = Database::pdo();
$total = 0;

foreach ($pdo->query('SELECT id, link_href, actions FROM occasions')->fetchAll() as $o) {
    $n = 0;
    $href = $o['link_href'] === null ? null : cleanLink((string) $o['link_href']);
    if ($href !== $o['link_href']) { $n++; }
    $actions = json_decode((string) $o['actions'], true);
    $newActions = is_array($actions) ? cleanTree($actions, $n) : $actions;
    if ($n > 0) {
        $pdo->prepare('UPDATE occasions SET link_href = ?, actions = ? WHERE id = ?')->execute([$href, json_encode($newActions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $o['id']]);
        $total += $n;
    }
}

foreach ($pdo->query('SELECT setting_key, value FROM site_settings')->fetchAll() as $s) {
    $n = 0;
    $v = json_decode((string) $s['value'], true);
    if (!is_array($v)) { continue; }
    $new = cleanTree($v, $n);
    if ($n > 0) {
        $pdo->prepare('UPDATE site_settings SET value = ? WHERE setting_key = ?')->execute([json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $s['setting_key']]);
        $total += $n;
    }
}
echo $total === 0 ? "Nothing to change. All saved links are already clean.\n" : "Cleaned $total saved link(s).\n";