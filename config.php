<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'sampah_app',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'python' => [
        'base_url' => rtrim(getenv('PY_MODEL_URL') ?: 'http://127.0.0.1:8010', '/'),
        'timeout_seconds' => (int) (getenv('PY_MODEL_TIMEOUT') ?: 120),
        'default_model' => getenv('PY_DEFAULT_MODEL') ?: 'indobert',
    ],
];
