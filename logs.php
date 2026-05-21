<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'db.php';

$token = getenv('LOG_VIEW_TOKEN') ?: '';
if ($token !== '') {
    $given = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    if (!hash_equals($token, $given)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = db();

$tab = isset($_GET['tab']) && is_string($_GET['tab']) ? strtolower(trim($_GET['tab'])) : 'chat';
if (!in_array($tab, ['chat', 'access'], true)) {
    $tab = 'chat';
}

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 30;
if ($limit < 10) {
    $limit = 10;
}
if ($limit > 200) {
    $limit = 200;
}

$offset = ($page - 1) * $limit;

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$from = isset($_GET['from']) && is_string($_GET['from']) ? trim($_GET['from']) : '';
$to = isset($_GET['to']) && is_string($_GET['to']) ? trim($_GET['to']) : '';
$status = isset($_GET['status']) ? (int) $_GET['status'] : 0;

$params = [];

if ($tab === 'chat') {
    $where = ['1=1'];
    if ($q !== '') {
        $where[] = '(pertanyaan LIKE :q1 OR jawaban LIKE :q2 OR matched_question LIKE :q3 OR prediction_model LIKE :q4)';
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
        $params[':q4'] = '%' . $q . '%';
    }
    if ($from !== '') {
        $where[] = 'created_at >= :from';
        $params[':from'] = $from;
    }
    if ($to !== '') {
        $where[] = 'created_at <= :to';
        $params[':to'] = $to;
    }

    $countSql = 'SELECT COUNT(*) AS c FROM chat_logs WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int) ($stmt->fetch()['c'] ?? 0);

    $sql = 'SELECT id, created_at, konteks, pertanyaan, jawaban, prediction_model, prediction_score, matched_question, access_log_id
            FROM chat_logs
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    $where = ['1=1'];
    if ($q !== '') {
        $where[] = '(ip LIKE :q1 OR path LIKE :q2 OR user_agent LIKE :q3 OR referer LIKE :q4 OR method LIKE :q5)';
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
        $params[':q4'] = '%' . $q . '%';
        $params[':q5'] = '%' . $q . '%';
    }
    if ($from !== '') {
        $where[] = 'created_at >= :from';
        $params[':from'] = $from;
    }
    if ($to !== '') {
        $where[] = 'created_at <= :to';
        $params[':to'] = $to;
    }
    if ($status > 0) {
        $where[] = 'response_status = :status';
        $params[':status'] = $status;
    }

    $countSql = 'SELECT COUNT(*) AS c FROM access_logs WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int) ($stmt->fetch()['c'] ?? 0);

    $sql = 'SELECT id, created_at, ip, method, path, query_string, user_agent, referer, response_status, response_time_ms
            FROM access_logs
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$totalPages = max(1, (int) ceil($total / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}

function buildUrl(array $override = []): string
{
    $params = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
            continue;
        }
        $params[$k] = $v;
    }
    $qs = http_build_query($params);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/logs.php', PHP_URL_PATH) ?: '/logs.php';
    return $qs === '' ? $path : ($path . '?' . $qs);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Server - Chatbooth Sampah Pintar</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css">
    <style>
        *{box-sizing:border-box;font-family:Segoe UI, Tahoma, Geneva, Verdana, sans-serif}
        body{margin:0;background:linear-gradient(135deg,#0f2d1b,#0b2417);color:#0f172a}
        .wrap{max-width:1200px;margin:0 auto;padding:18px 14px 26px}
        .top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
        .brand{color:rgba(255,255,255,.92)}
        .brand h1{margin:0;font-size:1.4rem}
        .brand p{margin:6px 0 0;opacity:.9;line-height:1.45}
        .tabs{display:flex;gap:10px;flex-wrap:wrap}
        .tab{display:inline-flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;text-decoration:none}
        .tab.active{background:rgba(255,255,255,.92);color:#0f172a;border:1px solid rgba(255,255,255,.22)}
        .tab.inactive{background:rgba(255,255,255,.12);color:rgba(255,255,255,.9);border:1px solid rgba(255,255,255,.18)}
        .card{margin-top:14px;background:rgba(255,255,255,.92);border-radius:16px;border:1px solid rgba(255,255,255,.22);box-shadow:0 12px 34px rgba(0,0,0,.26);overflow:hidden}
        .filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;padding:12px;border-bottom:1px solid rgba(15,23,42,.08);background:rgba(248,250,252,.92)}
        .filters input,.filters select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(15,23,42,.14);outline:none;background:rgba(255,255,255,.92)}
        .filters button{padding:10px 14px;border-radius:12px;border:none;background:linear-gradient(135deg,rgba(16,185,129,1),rgba(34,197,94,.95));color:rgba(255,255,255,.98);cursor:pointer}
        .meta{display:flex;gap:10px;flex-wrap:wrap;padding:12px}
        .pill{display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border-radius:999px;background:rgba(15,23,42,.06);border:1px solid rgba(15,23,42,.08);font-size:.9rem}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px 12px;border-top:1px solid rgba(15,23,42,.08);vertical-align:top}
        th{font-size:.9rem;text-align:left;background:rgba(248,250,252,.92);position:sticky;top:0;z-index:1}
        td{font-size:.92rem;color:rgba(15,23,42,.92)}
        .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;font-size:.85rem}
        .muted{color:rgba(15,23,42,.6)}
        .nowrap{white-space:nowrap}
        .pager{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px;border-top:1px solid rgba(15,23,42,.08);background:rgba(248,250,252,.92);flex-wrap:wrap}
        .pager a{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;text-decoration:none;color:#0f172a;border:1px solid rgba(15,23,42,.14);background:rgba(255,255,255,.92)}
        .pager a.disabled{opacity:.5;pointer-events:none}
        @media (max-width: 920px){.filters{grid-template-columns:1fr 1fr;}}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="top">
            <div class="brand">
                <h1><i class="fa-solid fa-list-check"></i> Log Server</h1>
                <p>Monitoring request (access_logs) dan percakapan chatbot (chat_logs).</p>
            </div>
            <div class="tabs">
                <a class="tab <?php echo $tab === 'chat' ? 'active' : 'inactive'; ?>" href="<?php echo h(buildUrl(['tab' => 'chat', 'page' => 1])); ?>">
                    <i class="fa-solid fa-comments"></i> Chat Logs
                </a>
                <a class="tab <?php echo $tab === 'access' ? 'active' : 'inactive'; ?>" href="<?php echo h(buildUrl(['tab' => 'access', 'page' => 1])); ?>">
                    <i class="fa-solid fa-globe"></i> Access Logs
                </a>
            </div>
        </div>

        <div class="card">
            <form class="filters" method="get" action="">
                <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
                <?php if ($token !== ''): ?>
                    <input type="hidden" name="token" value="<?php echo h(isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : ''); ?>">
                <?php endif; ?>
                <input name="q" value="<?php echo h($q); ?>" placeholder="Cari (pertanyaan/jawaban/path/ip/model)...">
                <input name="from" value="<?php echo h($from); ?>" placeholder="From (YYYY-MM-DD)">
                <input name="to" value="<?php echo h($to); ?>" placeholder="To (YYYY-MM-DD)">
                <?php if ($tab === 'access'): ?>
                    <input name="status" value="<?php echo $status > 0 ? h((string) $status) : ''; ?>" placeholder="Status (200/404/500)">
                <?php else: ?>
                    <select name="limit">
                        <?php foreach ([30, 50, 100, 200] as $l): ?>
                            <option value="<?php echo $l; ?>" <?php echo $limit === $l ? 'selected' : ''; ?>>Limit <?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
            </form>

            <div class="meta">
                <span class="pill"><i class="fa-solid fa-database"></i> Total: <b><?php echo h((string) $total); ?></b></span>
                <span class="pill"><i class="fa-solid fa-layer-group"></i> Page: <b><?php echo h((string) $page); ?></b> / <?php echo h((string) $totalPages); ?></span>
                <span class="pill"><i class="fa-solid fa-list"></i> Limit: <b><?php echo h((string) $limit); ?></b></span>
                <span class="pill"><i class="fa-solid fa-filter"></i> Tab: <b><?php echo h($tab); ?></b></span>
            </div>

            <?php if ($tab === 'chat'): ?>
                <table>
                    <thead>
                        <tr>
                            <th class="nowrap">ID</th>
                            <th class="nowrap">Waktu</th>
                            <th>Pertanyaan</th>
                            <th>Jawaban</th>
                            <th class="nowrap">Model</th>
                            <th class="nowrap">Score</th>
                            <th>Matched</th>
                            <th class="nowrap">Access ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="mono nowrap"><?php echo h((string) $r['id']); ?></td>
                                <td class="mono nowrap"><?php echo h((string) $r['created_at']); ?></td>
                                <td><?php echo h((string) $r['pertanyaan']); ?></td>
                                <td><?php echo h((string) $r['jawaban']); ?></td>
                                <td class="mono nowrap"><?php echo h((string) ($r['prediction_model'] ?? '')); ?></td>
                                <td class="mono nowrap"><?php echo h((string) ($r['prediction_score'] ?? '')); ?></td>
                                <td class="muted"><?php echo h((string) ($r['matched_question'] ?? '')); ?></td>
                                <td class="mono nowrap"><?php echo h((string) ($r['access_log_id'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th class="nowrap">ID</th>
                            <th class="nowrap">Waktu</th>
                            <th class="nowrap">IP</th>
                            <th class="nowrap">Method</th>
                            <th>Path</th>
                            <th class="nowrap">Status</th>
                            <th class="nowrap">Time (ms)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="mono nowrap"><?php echo h((string) $r['id']); ?></td>
                                <td class="mono nowrap"><?php echo h((string) $r['created_at']); ?></td>
                                <td class="mono nowrap"><?php echo h((string) ($r['ip'] ?? '')); ?></td>
                                <td class="mono nowrap"><?php echo h((string) ($r['method'] ?? '')); ?></td>
                                <td class="mono"><?php echo h((string) ($r['path'] ?? '')); ?></td>
                                <td class="mono nowrap"><?php echo h((string) ($r['response_status'] ?? '')); ?></td>
                                <td class="mono nowrap"><?php echo h((string) ($r['response_time_ms'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="pager">
                <?php
                $prev = $page > 1 ? $page - 1 : 1;
                $next = $page < $totalPages ? $page + 1 : $totalPages;
                ?>
                <a class="<?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo h(buildUrl(['page' => $prev])); ?>">
                    <i class="fa-solid fa-arrow-left"></i> Prev
                </a>
                <span class="muted">Gunakan filter untuk cari data lebih cepat.</span>
                <a class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo h(buildUrl(['page' => $next])); ?>">
                    Next <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
