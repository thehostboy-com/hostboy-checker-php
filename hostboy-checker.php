<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function memoryLimitBytes(): ?float
{
    $limit = ini_get('memory_limit');
    if (!preg_match('/^(\d+)\s*([KMG]?)$/i', (string) $limit, $parts)) {
        return null;
    }
    $factor = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];
    return (float) $parts[1] * $factor[strtoupper($parts[2])];
}

function diskTiming(string $block): ?float
{
    $file = @tempnam(sys_get_temp_dir(), 'hostboy-');
    if ($file === false) {
        return null;
    }
    try {
        $start = hrtime(true);
        if (@file_put_contents($file, $block) !== strlen($block) || @file_get_contents($file) === false) {
            return null;
        }
        return (hrtime(true) - $start) / 1000000;
    } finally {
        @unlink($file);
    }
}

try {
    $key = getenv('HOSTBOY_CHECKER_KEY');
    $endpoint = getenv('HOSTBOY_REPORT_URL');
    $url = $endpoint ? parse_url($endpoint) : false;
    $scheme = $url['scheme'] ?? '';
    if (!$key || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || ($scheme !== 'https' &&
        !($scheme === 'http' && $url['host'] === '127.0.0.1'))) {
        throw new RuntimeException('Configure an HTTPS report URL and checker key');
    }
    $block = str_repeat('a', 1048576);
    $start = hrtime(true);
    for ($i = 0; $i < 32; $i++) {
        hash('sha256', $block, true);
    }
    $hashMs = (hrtime(true) - $start) / 1000000;
    $payload = json_encode([
        'runtime' => 'php',
        'schemaVersion' => 1,
        'version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
        'suite' => 'hostboy-v1',
        'requestId' => bin2hex(random_bytes(16)),
        'hashMs' => $hashMs,
        'diskMs' => diskTiming($block),
        'memoryLimitBytes' => memoryLimitBytes(),
        'availableParallelism' => null,
    ], JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer {$key}\r\nContent-Type: application/json",
        'content' => $payload,
        'timeout' => 10,
        'follow_location' => 0,
        'ignore_errors' => true,
    ]]);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $result = @file_get_contents($endpoint, false, $context);
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        $status = 0;
        if (preg_match('/^HTTP\/\S+ (\d{3})\b/', $headers[0] ?? '', $matches)) {
            $status = (int) $matches[1];
        }
        if ($status === 201 && $result !== false) {
            break;
        }
        if (($status >= 400 && $status < 500) || $attempt === 2) {
            throw new RuntimeException($status ? 'Report rejected (HTTP ' . $status . ')' : 'TheHostBoy report was not accepted');
        }
        usleep((int) (250000 * (2 ** $attempt)));
    }
    echo "TheHostBoy report accepted\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
