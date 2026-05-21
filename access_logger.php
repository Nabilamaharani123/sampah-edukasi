<?php

declare(strict_types=1);

final class AccessLogger
{
    private PDO $pdo;
    private float $start;
    private ?int $id = null;

    public function __construct(PDO $pdo, ?string $rawBody)
    {
        $this->pdo = $pdo;
        $this->start = microtime(true);

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        $path = null;
        $queryString = null;
        if (is_string($uri) && $uri !== '') {
            $parts = parse_url($uri);
            if (is_array($parts)) {
                $path = $parts['path'] ?? null;
                $queryString = $parts['query'] ?? null;
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO access_logs (ip, method, path, query_string, user_agent, referer, request_body)
             VALUES (:ip, :method, :path, :query_string, :user_agent, :referer, :request_body)'
        );
        $stmt->execute([
            ':ip' => $ip,
            ':method' => $method,
            ':path' => $path,
            ':query_string' => $queryString,
            ':user_agent' => $userAgent,
            ':referer' => $referer,
            ':request_body' => $rawBody,
        ]);

        $this->id = (int) $this->pdo->lastInsertId();

        register_shutdown_function(function (): void {
            $this->finalize(http_response_code());
        });
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function finalize(int $statusCode): void
    {
        if ($this->id === null) {
            return;
        }

        $elapsedMs = (int) round((microtime(true) - $this->start) * 1000);

        $stmt = $this->pdo->prepare(
            'UPDATE access_logs
             SET response_status = :status, response_time_ms = :ms
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $statusCode,
            ':ms' => $elapsedMs,
            ':id' => $this->id,
        ]);
    }
}
