<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../include/nstp-component-content.php';
require_once __DIR__ . '/../conn/conn.php';

$limit = max(1, min(8, (int) ($_GET['limit'] ?? 4)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$items = getNstpFeaturedActivities($conn ?? null);
$total = count($items);

if ($offset >= $total) {
    $offset = max(0, $total - $limit);
}

$slice = array_slice($items, $offset, $limit);

echo json_encode([
    'items' => array_values($slice),
    'offset' => $offset,
    'limit' => $limit,
    'total' => $total,
    'has_more' => ($offset + $limit) < $total,
], JSON_UNESCAPED_SLASHES);
