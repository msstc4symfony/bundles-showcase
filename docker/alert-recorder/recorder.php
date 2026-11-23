<?php

declare(strict_types=1);

// Alertmanager webhook receiver for e2e: POST stores the notification body, GET returns every stored body as a JSON
// list (oldest first), DELETE forgets them. /_/* answers the compose healthcheck.
const STORE = '/tmp/alert-recorder.jsonl';

function fail(string $message): never
{
    http_response_code(500);
    file_put_contents('php://stderr', 'Alert recorder: ' . $message . "\n");
    echo $message;
    exit;
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(\is_string($uri) ? $uri : '/', PHP_URL_PATH);

if (str_starts_with($path, '/_/')) {
    echo 'ok';

    return;
}

$handle = @fopen(STORE, 'c+');
if ($handle === false) {
    fail('Cannot open ' . STORE);
}
if (!flock($handle, LOCK_EX)) {
    fail('Cannot lock ' . STORE);
}

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
        $record = json_encode([
            'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'receiver' => $payload instanceof stdClass ? ($payload->receiver ?? null) : null,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $record) !== \strlen($record) || !fflush($handle)) {
            fail('Cannot append to ' . STORE);
        }
        file_put_contents('php://stderr', 'Alert recorder stored a notification for ' . $path . "\n");
        http_response_code(204);
        break;
    case 'DELETE':
        if (!ftruncate($handle, 0)) {
            fail('Cannot truncate ' . STORE);
        }
        http_response_code(204);
        break;
    case 'GET':
        $stored = stream_get_contents($handle);
        if ($stored === false) {
            fail('Cannot read ' . STORE);
        }
        $lines = array_filter(explode("\n", $stored));
        header('Content-Type: application/json');
        echo '[' . implode(',', $lines) . ']';
        break;
    default:
        http_response_code(405);
}

flock($handle, LOCK_UN);
fclose($handle);
