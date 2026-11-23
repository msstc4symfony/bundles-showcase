<?php

declare(strict_types=1);

// Alertmanager webhook receiver for e2e: POST stores the notification body, GET returns every stored body as a JSON
// list (oldest first), DELETE forgets them. /_/* answers the compose healthcheck.
const STORE = '/tmp/alert-recorder.jsonl';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (str_starts_with($path, '/_/')) {
    echo 'ok';

    return;
}

$handle = @fopen(STORE, 'c+');
if ($handle === false) {
    http_response_code(500);
    echo 'Cannot open ' . STORE;

    return;
}
flock($handle, LOCK_EX);

switch ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
    case 'POST':
        $body = (string) file_get_contents('php://input');
        try {
            // Objects, not arrays: an empty "annotations": {} must stay an object for e2e.
            $payload = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            http_response_code(400);
            echo $e->getMessage();
            break;
        }
        fseek($handle, 0, SEEK_END);
        fwrite($handle, json_encode([
            'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'receiver' => $payload instanceof stdClass ? ($payload->receiver ?? null) : null,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents('php://stderr', 'Alert recorder stored a notification for ' . $path . "\n");
        http_response_code(204);
        break;
    case 'DELETE':
        ftruncate($handle, 0);
        http_response_code(204);
        break;
    case 'GET':
        $lines = array_filter(explode("\n", (string) stream_get_contents($handle)));
        header('Content-Type: application/json');
        echo '[' . implode(',', $lines) . ']';
        break;
    default:
        http_response_code(405);
}

flock($handle, LOCK_UN);
fclose($handle);
