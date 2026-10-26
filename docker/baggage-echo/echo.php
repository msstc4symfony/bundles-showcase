<?php

declare(strict_types=1);

// Logs the trace id, the trace headers and the baggage of each request as one JSON line shaped like the apps'
// logs, for e2e to read from Loki.
$traceParent = $_SERVER['HTTP_TRACEPARENT'] ?? null;
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// The compose healthcheck polls every few seconds; e2e reads only the probe's requests.
if (str_starts_with($path, '/_/')) {
    echo 'ok';

    return;
}

file_put_contents('php://stderr', json_encode([
    'message' => 'Baggage echo received ' . $path,
    'trace_id' => explode('-', $traceParent ?? '')[1] ?? null,
    'extra' => [
        'path' => $path,
        'traceparent' => $traceParent,
        'tracestate' => $_SERVER['HTTP_TRACESTATE'] ?? null,
        'baggage' => $_SERVER['HTTP_BAGGAGE'] ?? null,
    ],
], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n");

echo 'echo';
