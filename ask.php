<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET' && (($_GET['health'] ?? '') === '1')) {
    $config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    $python = $config['python'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $python['base_url'] . '/health',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 2,
    ]);

    $respBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(502);
    echo json_encode(
        [
            'status' => 'error',
            'detail' => $curlErr !== '' ? $curlErr : $respBody,
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$question = isset($data['question']) && is_string($data['question']) ? trim($data['question']) : '';
$context = isset($data['context']) && is_string($data['context']) ? trim($data['context']) : null;
$model = isset($data['model']) && is_string($data['model']) ? trim($data['model']) : null;

if ($question === '') {
    http_response_code(400);
    echo json_encode(['error' => 'question is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'access_logger.php';

$pdo = db();
$logger = new AccessLogger($pdo, $rawBody);

$config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
$python = $config['python'];
$modelToUse = $model !== '' && $model !== null ? $model : $python['default_model'];

$payload = [
    'question' => $question,
    'context' => $context,
    'model' => $modelToUse,
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $python['base_url'] . '/predict',
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => $python['timeout_seconds'],
]);

$respBody = curl_exec($ch);
$curlErr = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($respBody) || $respBody === '' || $status < 200 || $status >= 300) {
    http_response_code(502);
    echo json_encode(
        [
            'error' => 'Python model server error',
            'status' => $status,
            'detail' => $curlErr !== '' ? $curlErr : $respBody,
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$pred = json_decode($respBody, true);
if (!is_array($pred) || !isset($pred['answer'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid response from model server'], JSON_UNESCAPED_UNICODE);
    exit;
}

$answer = is_string($pred['answer']) ? $pred['answer'] : '';
$score = isset($pred['score']) ? (float) $pred['score'] : null;
$matchedQuestion = isset($pred['matched_question']) && is_string($pred['matched_question']) ? $pred['matched_question'] : null;
$usedModel = isset($pred['model']) && is_string($pred['model']) ? $pred['model'] : $modelToUse;

$stmt = $pdo->prepare(
    'INSERT INTO chat_logs (konteks, pertanyaan, jawaban, prediction_model, prediction_score, matched_question, access_log_id)
     VALUES (:konteks, :pertanyaan, :jawaban, :prediction_model, :prediction_score, :matched_question, :access_log_id)'
);
$stmt->execute([
    ':konteks' => $context,
    ':pertanyaan' => $question,
    ':jawaban' => $answer,
    ':prediction_model' => $usedModel,
    ':prediction_score' => $score,
    ':matched_question' => $matchedQuestion,
    ':access_log_id' => $logger->id(),
]);

echo json_encode(
    [
        'answer' => $answer,
        'score' => $score,
        'model' => $usedModel,
        'matched_question' => $matchedQuestion,
    ],
    JSON_UNESCAPED_UNICODE
);
