# TheHostBoy checker for PHP

Measures a bounded SHA-256 workload, temporary-file read/write timing, PHP memory limit, and PHP runtime version, then submits one schema v1 report to TheHostBoy.

## Requirements

- PHP CLI 8.0 or newer with `random_bytes`, `hrtime`, and HTTPS stream support
- HTTPS access to your TheHostBoy instance

No Composer packages are required. Run from CLI; the script returns 404 if invoked through a web request.

## Community checker setup

Create an account and checker installation on TheHostBoy, verify a domain you control, and copy the one-time key. Use the community report endpoint:

```sh
export HOSTBOY_REPORT_URL='https://HOSTBOY_DOMAIN/api/v1/community/reports'
export HOSTBOY_CHECKER_KEY='YOUR_INSTALLATION_KEY'
php hostboy-checker.php
```

The report URL and key are provided by TheHostBoy. Keep the key private; it allows submissions for this installation. To run periodically, configure both environment variables in the hosting account's cron environment and schedule `php /absolute/path/hostboy-checker.php`. Do not commit a key or a `.env` file.

The script sends a report at most once per run, retries bounded transient failures, times out requests, and does not follow redirects. It performs local CPU and temporary-storage work; it does not scan site files or collect visitor data.

The default endpoint for a user-contributed installation is `/api/v1/community/reports`. Provider-authorized installations use the exact report URL supplied by an administrator. Community results are labeled self-reported and kept out of provider plan rankings.

## Shared-host web checker

`index.php` adds a branded, user-initiated web check for PHP shared hosting without CLI or cron. Copy `index.php` into the site root and the configured `config.php` into `.hostboy-private/`. Keep that directory protected by the supplied deny-all `.htaccess` file. The config returns 404 if requested directly and is loaded only by `index.php`.

Set `mode` to `form` to show a Run a fresh check button, or `auto` to run on page load. The shared installation cooldown defaults to 60 seconds and cannot be configured below 60 seconds. The page shows the last run and its PHP version, hash timing, temporary-file timing, and memory limit. Every accepted sample is sent to `reportUrl` using `checkerKey`; the key is never sent to the browser. The `.hostboy-private` directory must contain the supplied deny-all `.htaccess` file because it stores the last-run state and lock.

The CLI checker remains available as `hostboy-checker.php`. Across the Node, Python, shell, and PHP CLI repos, configuration uses the same `HOSTBOY_REPORT_URL` and `HOSTBOY_CHECKER_KEY` environment variables; the web page uses the equivalent keys in its private PHP config.
