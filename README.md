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
