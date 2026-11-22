<?php
declare(strict_types=1);

require __DIR__ . '/backend/src/bootstrap.php';

use App\Database;

function metaEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$host = (string) ($_SERVER['HTTP_HOST'] ?? 'ogaaward.xyz');
if (!preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/i', $host)) {
    $host = 'ogaaward.xyz';
}

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$scheme = $https ? 'https' : 'http';
$basePath = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
$baseUrl = $scheme . '://' . $host . $basePath;

$nominee = null;

if ($id !== false && $id !== null && $id > 0) {
    $stmt = Database::pdo()->prepare(
        'SELECT n.name, n.department, n.level, n.photo_path,
                c.name AS category_name,
                e.name AS edition_name, e.edition_label, e.year
           FROM nominee_entries n
           JOIN categories c ON c.id = n.category_id
           JOIN editions e ON e.id = n.edition_id
          WHERE n.id = ?
            AND n.status = \'approved\'
            AND e.status <> \'draft\'
          LIMIT 1'
    );

    $stmt->execute([$id]);
    $nominee = $stmt->fetch() ?: null;
}

if (!$nominee) {
    http_response_code(404);
    $title = 'Nominee Not Found | OGA Awards';
    $description = 'This nominee profile does not exist or is not publicly available.';
    $image = $baseUrl . '/assets/images/logo/logo.jpeg';
    $canonical = $baseUrl . '/nominee';
} else {
    $name = trim((string) $nominee['name']);
    $category = trim((string) $nominee['category_name']);
    $edition = trim((string) $nominee['edition_name'] . ' ' . (string) $nominee['edition_label']);
    $details = array_filter([
        trim((string) ($nominee['department'] ?? '')),
        trim((string) ($nominee['level'] ?? '')),
    ]);

    $title = $name . ' | ' . $edition . ' OGA Awards';

    $description = 'Official nominee in ' . $category . ' at ' . $edition
        . ' OGA Awards' . ($details ? '. ' . implode(' · ', $details) : '.');

    $photo = (string) ($nominee['photo_path'] ?? '');

    if (
        $photo !== ''
        && str_starts_with($photo, 'uploads/')
        && !str_contains($photo, '..')
    ) {
        $image = $baseUrl . '/' . implode(
            '/',
            array_map('rawurlencode', explode('/', $photo))
        );
    } else {
        $image = $baseUrl . '/assets/images/logo/logo.jpeg';
    }

    $canonical = $baseUrl . '/nominee?id=' . $id;
}

$template = file_get_contents(__DIR__ . '/nominee.html');

if ($template === false) {
    http_response_code(500);
    exit('Page could not be loaded.');
}

$escapedTitle = metaEscape($title);
$escapedDescription = metaEscape($description);
$escapedImage = metaEscape($image);
$escapedCanonical = metaEscape($canonical);

$template = str_replace(
    '<title>Nominee — OGA Awards</title>',
    '<title>' . $escapedTitle . '</title>',
    $template
);

$template = str_replace(
    '<meta name="description" content="Nominee profile.">',
    '<meta name="description" content="' . $escapedDescription . '">',
    $template
);

$metadata = '
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="' . $escapedCanonical . '">

  <meta property="og:type" content="profile">
  <meta property="og:site_name" content="OGA Awards">
  <meta property="og:locale" content="en_NG">
  <meta property="og:title" content="' . $escapedTitle . '">
  <meta property="og:description" content="' . $escapedDescription . '">
  <meta property="og:url" content="' . $escapedCanonical . '">
  <meta property="og:image" content="' . $escapedImage . '">
  <meta property="og:image:alt" content="' . $escapedTitle . '">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="' . $escapedTitle . '">
  <meta name="twitter:description" content="' . $escapedDescription . '">
  <meta name="twitter:image" content="' . $escapedImage . '">
';

$template = str_replace('</head>', $metadata . "\n</head>", $template);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $template;
