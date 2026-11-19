<?php
declare(strict_types=1);

header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$privateDir = __DIR__ . '/.hostboy-private';
define('HOSTBOY_CHECKER_BOOTSTRAP', true);
$configPath = $privateDir . '/config.php';
if (!is_file($configPath) || !is_readable($configPath)) {
    http_response_code(503);
    exit('Checker setup is incomplete.');
}
$config = require $configPath;
$configDefaults = [
    'mode' => 'form', 'cooldownSeconds' => 60, 'reportUrl' => '', 'checkerKey' => '',
    'provider' => 'Hosting provider', 'plan' => 'Plan', 'region' => 'Not disclosed',
    'configuration' => 'PHP web runtime', 'brand' => 'TheHostBoy',
    'mysql' => ['enabled' => false, 'host' => '', 'port' => 3306, 'database' => '', 'username' => '', 'password' => '', 'connectTimeoutSeconds' => 5, 'readTimeoutSeconds' => 5],
];
if (!is_array($config)) $config = [];
$config = array_merge($configDefaults, $config);
$mysqlConfig = is_array($config['mysql']) ? array_merge($configDefaults['mysql'], $config['mysql']) : null;
$endpoint = parse_url((string) $config['reportUrl']);
if (!in_array($config['mode'], ['form', 'auto'], true) || !is_int($config['cooldownSeconds']) || $config['cooldownSeconds'] < 60 || $config['cooldownSeconds'] > 86400 ||
    !is_array($endpoint) || ($endpoint['scheme'] ?? '') !== 'https' || empty($endpoint['host']) || isset($endpoint['user']) || isset($endpoint['pass']) || isset($endpoint['fragment']) ||
    !is_string($config['checkerKey']) || strlen($config['checkerKey']) < 32 || $mysqlConfig === null || !is_bool($mysqlConfig['enabled'])) {
    http_response_code(503);
    exit('Checker configuration is invalid.');
}
if ($mysqlConfig['enabled'] &&
    (!is_string($mysqlConfig['host']) || $mysqlConfig['host'] === '' || strlen($mysqlConfig['host']) > 253 || !preg_match('/^[A-Za-z0-9.-]+$/', $mysqlConfig['host']) ||
        !is_string($mysqlConfig['database']) || $mysqlConfig['database'] === '' || strlen($mysqlConfig['database']) > 64 ||
        !is_string($mysqlConfig['username']) || $mysqlConfig['username'] === '' || strlen($mysqlConfig['username']) > 64 ||
        !is_string($mysqlConfig['password']) || $mysqlConfig['password'] === '' || strlen($mysqlConfig['password']) > 255 ||
        !is_int($mysqlConfig['port']) || $mysqlConfig['port'] < 1 || $mysqlConfig['port'] > 65535 ||
        !is_int($mysqlConfig['connectTimeoutSeconds']) || $mysqlConfig['connectTimeoutSeconds'] < 1 || $mysqlConfig['connectTimeoutSeconds'] > 15 ||
        !is_int($mysqlConfig['readTimeoutSeconds']) || $mysqlConfig['readTimeoutSeconds'] < 1 || $mysqlConfig['readTimeoutSeconds'] > 15)) {
    http_response_code(503);
    exit('Checker configuration is invalid.');
}

if (!is_file($privateDir . '/.htaccess') || !is_dir($privateDir)) {
    http_response_code(503);
    exit('Private checker storage is not protected.');
}
$statePath = $privateDir . '/index-state.json';
$lockPath = $privateDir . '/index-run.lock';
$lastRun = static function () use ($statePath): array {
    $data = @file_get_contents($statePath);
    $state = is_string($data) ? json_decode($data, true) : null;
    return is_array($state) ? $state : [];
};
$saveState = static function (array $state) use ($privateDir, $statePath): bool {
    $encoded = json_encode($state, JSON_THROW_ON_ERROR);
    $temporary = @tempnam($privateDir, '.state-');
    if ($temporary === false) return false;
    try {
        $written = @file_put_contents($temporary, $encoded, LOCK_EX);
        if ($written !== strlen($encoded)) return false;
        @chmod($temporary, 0600);
        return @rename($temporary, $statePath);
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
};
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$message = '';
$runMetrics = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}
$shouldRun = $config['mode'] === 'auto' ? $method === 'GET' : $method === 'POST';

function memoryLimitBytes(): ?int
{
    $limit = ini_get('memory_limit');
    if (!preg_match('/^(\d+)\s*([KMG]?)$/i', (string) $limit, $parts)) return null;
    return (int) $parts[1] * ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($parts[2])];
}

function mysqlCheck(array $settings): array
{
    if (!$settings['enabled']) return ['mysqlStatus' => 'disabled', 'mysqlQueryMs' => null];
    if (!extension_loaded('mysqli')) return ['mysqlStatus' => 'unavailable', 'mysqlQueryMs' => null];

    $started = hrtime(true);
    $connection = null;
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $connection = mysqli_init();
        if ($connection === false) throw new RuntimeException('MySQL client unavailable');
        if (!$connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, $settings['connectTimeoutSeconds']) ||
            !$connection->options(MYSQLI_OPT_READ_TIMEOUT, $settings['readTimeoutSeconds'])) {
            throw new RuntimeException('MySQL timeouts unavailable');
        }
        if (!$connection->real_connect($settings['host'], $settings['username'], $settings['password'], $settings['database'], $settings['port'])) {
            throw new RuntimeException('MySQL connection failed');
        }
        $result = $connection->query('SELECT 1');
        if ($result === false) throw new RuntimeException('MySQL probe failed');
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') throw new RuntimeException('MySQL probe failed');
        return ['mysqlStatus' => 'connected', 'mysqlQueryMs' => (hrtime(true) - $started) / 1000000];
    } catch (Throwable $error) {
        return ['mysqlStatus' => 'failed', 'mysqlQueryMs' => (hrtime(true) - $started) / 1000000];
    } finally {
        if ($connection instanceof mysqli) $connection->close();
    }
}

if ($shouldRun) {
    $lock = @fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        $message = 'A check is already running. Please wait and refresh.';
    } else {
        $state = $lastRun();
        $lastStarted = (int) ($state['startedAt'] ?? 0);
        $remaining = $config['cooldownSeconds'] - (time() - $lastStarted);
        if ($lastStarted > 0 && $remaining > 0) {
            $message = 'A check ran recently. Try again in ' . $remaining . ' seconds.';
        } else {
            $state = ['startedAt' => time(), 'status' => 'running'];
            if (!$saveState($state)) {
                $message = 'The check could not start because its rate-limit state is unavailable.';
            } else try {
                $block = str_repeat('a', 1048576);
                $start = hrtime(true);
                for ($i = 0; $i < 32; $i++) hash('sha256', $block, true);
                $hashMs = (hrtime(true) - $start) / 1000000;
                $diskMs = null;
                $tmpFile = @tempnam(sys_get_temp_dir(), 'hostboy-');
                if ($tmpFile !== false) {
                    try {
                        $start = hrtime(true);
                        if (@file_put_contents($tmpFile, $block) === strlen($block) && @file_get_contents($tmpFile) !== false) $diskMs = (hrtime(true) - $start) / 1000000;
                    } finally { @unlink($tmpFile); }
                }
                $mysqlMetrics = mysqlCheck($mysqlConfig);
                $requestId = bin2hex(random_bytes(16));
                $payload = json_encode([
                    'runtime' => 'php', 'schemaVersion' => 1,
                    'version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
                    'suite' => 'hostboy-v1', 'requestId' => $requestId, 'hashMs' => $hashMs,
                    'diskMs' => $diskMs, 'memoryLimitBytes' => memoryLimitBytes(), 'availableParallelism' => null,
                    'mysqlStatus' => $mysqlMetrics['mysqlStatus'], 'mysqlQueryMs' => $mysqlMetrics['mysqlQueryMs'],
                ], JSON_THROW_ON_ERROR);
                $context = stream_context_create(['http' => [
                    'method' => 'POST',
                    'header' => "Authorization: Bearer {$config['checkerKey']}\r\nContent-Type: application/json\r\n",
                    'content' => $payload, 'timeout' => 10, 'follow_location' => 0, 'ignore_errors' => true,
                ]]);
                $accepted = false;
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $response = @file_get_contents($config['reportUrl'], false, $context);
                    $headers = $http_response_header ?? [];
                    $status = 0;
                    if (preg_match('/^HTTP\/\S+ (\d{3})\b/', $headers[0] ?? '', $matches)) $status = (int) $matches[1];
                    if ($status === 201 && $response !== false) { $accepted = true; break; }
                    if (($status >= 400 && $status < 500) || $attempt === 2) break;
                    usleep((int) (250000 * (2 ** $attempt)));
                }
                $runMetrics = ['phpVersion' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION, 'hashMs' => $hashMs,
                    'diskMs' => $diskMs, 'memoryLimitBytes' => memoryLimitBytes()] + $mysqlMetrics;
                $state = ['startedAt' => time(), 'status' => $accepted ? 'accepted' : 'rejected', 'metrics' => $runMetrics];
                $message = $accepted ? 'Check complete. TheHostBoy accepted the report.' : 'The check ran, but the report was not accepted.';
            } catch (Throwable $error) {
                $state = ['startedAt' => time(), 'status' => 'error'];
                $message = 'The check failed. Please try again after the cooldown.';
            }
            if (!$saveState($state)) $message = 'The result could not be saved; the cooldown remains active.';
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

$state = $lastRun();
$lastTimestamp = (int) ($state['startedAt'] ?? 0);
$lastMetrics = is_array($state['metrics'] ?? null) ? $state['metrics'] : null;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#073d3b"><title><?= $escape($config['brand']) ?> · Hosting check</title>
<style>
:root{color-scheme:light;--ink:#153b3b;--muted:#5d6e68;--cream:#fff8eb;--paper:#fffdf7;--coral:#dc6655;--teal:#073d3b;--line:#eadfce}*{box-sizing:border-box}body{margin:0;background:linear-gradient(145deg,#fff2df,var(--cream) 42%,#f4ead9);color:var(--ink);font:16px/1.55 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:780px;margin:0 auto;padding:36px 20px 64px}.brand{font-weight:850;letter-spacing:-.04em;font-size:22px}.brand span{color:var(--coral)}.card{margin-top:26px;background:var(--paper);border:1px solid var(--line);border-radius:22px;padding:clamp(22px,5vw,42px);box-shadow:0 18px 55px #59402412}h1{font-size:clamp(30px,6vw,48px);line-height:1.08;letter-spacing:-.045em;margin:12px 0;color:var(--teal)}.eyebrow{color:var(--coral);font-size:12px;font-weight:800;letter-spacing:.15em;text-transform:uppercase}.intro,.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:24px 0}.metric,.info{border:1px solid var(--line);border-radius:14px;background:#fffaf0;padding:14px}.metric strong,.metric small{display:block}.metric strong{font-size:21px}.metric small{color:var(--muted)}.info{margin:16px 0}.run{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 22px;border:0;border-radius:12px;background:var(--coral);color:white;font:inherit;font-weight:800;text-decoration:none;cursor:pointer}.run:hover{filter:brightness(.94)}.message{padding:12px 14px;border-radius:12px;background:#f8ecdd;margin:18px 0}.foot{font-size:13px;color:var(--muted);margin-top:24px}a{color:var(--teal)}@media(max-width:480px){.wrap{padding:22px 14px 40px}.card{border-radius:18px}}
</style></head><body><main class="wrap"><div class="brand">TheHost<span>Boy</span></div><section class="card">
<div class="eyebrow">Live hosting check</div><h1><?= $escape($config['provider']) ?> · <?= $escape($config['plan']) ?></h1>
<p class="intro">Run a small PHP performance check. The result is sent to TheHostBoy and shown in the provider comparison.</p>
<div class="grid"><div class="info"><strong>Provider</strong><br><?= $escape($config['provider']) ?></div><div class="info"><strong>Plan</strong><br><?= $escape($config['plan']) ?></div><div class="info"><strong>Region</strong><br><?= $escape($config['region']) ?></div><div class="info"><strong>Configuration</strong><br><?= $escape($config['configuration']) ?></div></div>
<?php if ($message !== ''): ?><p class="message" role="status"><?= $escape($message) ?></p><?php endif; ?>
<?php if ($lastTimestamp > 0): ?><h2>Latest check</h2><p><strong><?= $escape(ucfirst((string) ($state['status'] ?? 'unknown'))) ?></strong> · <?= $escape(gmdate('Y-m-d H:i:s', $lastTimestamp)) ?> UTC</p>
<?php if ($lastMetrics): ?><div class="grid"><div class="metric"><strong><?= $escape(number_format((float) $lastMetrics['hashMs'], 2)) ?> ms</strong><small>SHA-256 sample</small></div><div class="metric"><strong><?= isset($lastMetrics['diskMs']) ? $escape(number_format((float) $lastMetrics['diskMs'], 2)) . ' ms' : 'Unavailable' ?></strong><small>Temporary file read/write</small></div><div class="metric"><strong><?= isset($lastMetrics['memoryLimitBytes']) ? $escape(number_format((float) $lastMetrics['memoryLimitBytes'] / 1048576, 0)) . ' MiB' : 'Unknown' ?></strong><small>PHP memory limit</small></div><div class="metric"><strong>PHP <?= $escape($lastMetrics['phpVersion'] ?? 'Unknown') ?></strong><small>Runtime version</small></div></div><?php endif; ?>
<?php if (isset($lastMetrics['mysqlStatus'])): ?><div class="grid"><div class="metric"><strong><?= $escape(ucfirst((string) $lastMetrics['mysqlStatus'])) ?></strong><small>MySQL connectivity check</small></div><div class="metric"><strong><?= isset($lastMetrics['mysqlQueryMs']) ? $escape(number_format((float) $lastMetrics['mysqlQueryMs'], 2)) . ' ms' : 'Unavailable' ?></strong><small>Connect and SELECT 1</small></div></div><?php endif; ?>
<?php else: ?><p class="muted">No checks have run yet.</p><?php endif; ?>
<?php if ($config['mode'] === 'form'): ?><form method="post"><button class="run" type="submit">Run a fresh check</button></form><?php elseif ($config['mode'] === 'auto'): ?><p class="muted">This page runs a check on load, subject to the configured cooldown.</p><?php endif; ?>
<p class="foot">One check per installation every <?= $escape($config['cooldownSeconds']) ?> seconds. Measurements are point-in-time samples, not an uptime guarantee. <a href="https://thehostboy.com/">Back to TheHostBoy</a></p>
</section></main></body></html>
