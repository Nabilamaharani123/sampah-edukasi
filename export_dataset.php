<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'access_logger.php';

$rawBody = null;
$pdo = db();
$logger = new AccessLogger($pdo, $rawBody);

$format = isset($_GET['format']) && is_string($_GET['format']) ? strtolower(trim($_GET['format'])) : 'json';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 5000;
if ($limit <= 0) {
    $limit = 5000;
}
if ($limit > 50000) {
    $limit = 50000;
}

$from = isset($_GET['from']) && is_string($_GET['from']) ? trim($_GET['from']) : null;
$to = isset($_GET['to']) && is_string($_GET['to']) ? trim($_GET['to']) : null;

$where = ['jawaban IS NOT NULL', "TRIM(jawaban) <> ''", 'pertanyaan IS NOT NULL', "TRIM(pertanyaan) <> ''"];
$params = [];
if (is_string($from) && $from !== '') {
    $where[] = 'created_at >= :from';
    $params[':from'] = $from;
}
if (is_string($to) && $to !== '') {
    $where[] = 'created_at <= :to';
    $params[':to'] = $to;
}

$sql = 'SELECT konteks, pertanyaan, jawaban
        FROM chat_logs
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY id DESC
        LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($format === 'jsonl') {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    foreach ($rows as $row) {
        echo json_encode(
            [
                'konteks' => $row['konteks'],
                'pertanyaan' => $row['pertanyaan'],
                'jawaban' => $row['jawaban'],
            ],
            JSON_UNESCAPED_UNICODE
        ) . "\n";
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$out = [];
foreach ($rows as $row) {
    $out[] = [
        'konteks' => $row['konteks'],
        'pertanyaan' => $row['pertanyaan'],
        'jawaban' => $row['jawaban'],
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
