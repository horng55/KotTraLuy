# Telegram production automation

## Status

The repository includes systemd unit templates, scheduler registrations, retry
corrections, delivery locks and a CLI health check. Templates are **not installed
services**. Production access is required to install and verify them. A local
Windows server or tunnel cannot deliver notifications when that computer is off.

## Server requirements and installation

Use an always-on Linux PHP 8.2+ host with systemd, outbound HTTPS to Telegram,
the production database and shared persistent database cache. The five templates
in [deploy/systemd](../deploy/systemd) assume:

- Stable application symlink: `/var/www/kottraluy/current/backend`
- PHP CLI: `/usr/bin/php`, service user/group: `www-data`
- Production environment: `APP_ENV=production`, `APP_DEBUG=false`,
  `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=120`, `CACHE_STORE=database`
- Existing bot token, username, webhook secret and URLs remain unchanged.

Adjust paths, PHP and service user to the actual host **before installation**.
Use the same production `.env` and persistent database/cache across web, worker
and scheduler processes. Do not copy local credentials or a local SQLite file.
Back up production, install dependencies, run approved outstanding migrations
(`php artisan migrate --force`) and cache configuration (`php artisan config:cache`).
Existing jobs, failed_jobs, cache and cache_locks migrations must be applied.

Install the reviewed units under `/etc/systemd/system/`, then run as an administrator:

```sh
systemctl daemon-reload
systemctl enable --now kottraluy-queue.service
systemctl enable --now kottraluy-scheduler.timer kottraluy-health.timer
systemctl status kottraluy-queue.service kottraluy-scheduler.timer kottraluy-health.timer
systemctl list-timers 'kottraluy-*'
```

Use exactly one scheduler timer for this application; remove an existing scheduler
cron entry only after identifying it. Do not run both cron and this timer. Laravel
locks prevent concurrent task executions; the timer also avoids overlapping its
own service. Worker restart is unconditional (including hourly worker recycling and
`queue:restart`), and services/timers start on reboot. Stop allows 90 seconds for
the current job, exceeding the 45-second Telegram job timeout; queue reservation
expiry is 120 seconds, exceeding the worker's 60-second timeout.

If the provider has no systemd access, configure its persistent worker service
with the same queue command, restart policy and environment. Its scheduler must
run `cd /actual/backend && /actual/php artisan schedule:run` every minute instead
of installing the timer. A request-only/serverless web host is insufficient.

## Schedule, retries and duplicate prevention

Every minute Laravel runs inventory checks (stock, out of stock, date-based expiry),
queues due reminders and records scheduler/queue heartbeats. Inventory heartbeat
is recorded only after successful checks. Existing alert/event keys and recipient
delivery uniqueness remain intact. Reminders atomically claim only already-sent,
unresolved notifications due at the workspace's saved interval. They do not reset
pending backoff or permanent failures or fan another user's notification out.

Per-delivery cache locks serialize queue execution. Retries use 60, 300, 900 and
1800 second delays with five attempts. Telegram 429 retry_after is respected;
permanent Telegram rejections remain in delivery diagnostics. Exhausted jobs
remain in failed_jobs and failed delivery records. Telegram cannot guarantee
exactly-once delivery if a process dies after Telegram accepts a message but before
the database records success; do not bulk replay jobs after an uncertain send.

## Monitoring and recovery

```sh
php artisan notifications:health
php artisan schedule:list
php artisan queue:failed
journalctl -u kottraluy-queue.service -u kottraluy-scheduler.service -u kottraluy-health.service --since '30 minutes ago'
```

Health exits nonzero for scheduler, queue or inventory heartbeats older than five
minutes, a synchronous queue, failed jobs/deliveries or pending deliveries older
than two hours. It sends no Telegram messages. The health timer writes failures to
systemd/journal; connect `kottraluy-health.service` failures to the host's external
monitoring/alerting service so someone is notified. Monitor the host itself from
outside it: an offline machine cannot run its own health checks. Historical failed
records continue failing health until reviewed and intentionally resolved.

For each failed job, inspect its delivery record/error and confirm whether Telegram
accepted it. Correct permissions, connectivity or recipient blocking first. Retry
only the reviewed UUID using `php artisan queue:retry UUID`; never blindly use
`queue:retry all`. Permanent Telegram rejections are delivery diagnostics and are
not automatically retried. Do not truncate queue/delivery/deduplication tables.

## Deployment

Drain/stop the queue before changing a release symlink, migrate and cache production
configuration, then start/restart the worker with systemd. For in-place releases,
run `php artisan config:cache` then `php artisan queue:restart`; systemd restarts
the gracefully exiting worker. For symlink releases, explicitly restart the worker
after switching the symlink because its old working directory points at the prior
release. Scheduler invocations load the current symlink on each tick. Keep database
cache/prefix stable so restart signals and locks are shared across releases.

## Production acceptance (requires server access)

1. Verify effective production environment/queue/cache and PHP paths without printing secrets.
2. Confirm worker PID, enabled state, timer last/next runs and fresh health heartbeats.
3. Confirm `telegram:diagnose` reports the official bot and existing matching webhook.
4. Choose one authorized connected recipient and send **one** test through the
   authenticated application's existing Send Test Notification action. Record
   Telegram acceptance and confirm receipt in that chat. Do not generate sample
   inventory or run bulk refresh commands repeatedly for testing.
5. Confirm one genuine due inventory delivery transitions pending to sent with a
   message ID through the worker; the UI test alone does not prove queue execution.
6. Check crash restart and reboot persistence during an agreed maintenance window.
7. Stop the local development computer and verify production health/delivery continue.

Do not mark these checks passed solely because mocked HTTP tests pass.

## Verified in this workspace

- Effective local configuration: environment `local`, queue `database`, cache
  `database`, official configured username `KotTraLuyBot`, bot token present.
  No bot token or webhook configuration was changed. Production environment is
  not verified from this local configuration.
- `php artisan schedule:list`: heartbeat, inventory refresh and reminder commands
  all registered every minute. Listing does not execute inventory checks or send.
- Complete backend suite: **97 tests, 638 assertions passed**. Six new automation
  tests cover due reminder claiming, preservation of pending/failed deliveries,
  delayed retry release, transient retry timing, sent-message deduplication,
  concurrent delivery locking, resolved alerts and heartbeat health transitions.
- Editor diagnostics: no errors reported.
- Production systemd installation, running service PIDs, reboot/crash recovery,
  external monitoring integration and live Telegram delivery: **not verified**;
  production host/access details were requested and are still needed.

### Files changed for automation

- [SendTelegramInventoryNotification.php](../backend/app/Jobs/SendTelegramInventoryNotification.php)
- [RecordQueueHeartbeat.php](../backend/app/Jobs/RecordQueueHeartbeat.php)
- [NotificationsHealth.php](../backend/app/Console/Commands/NotificationsHealth.php)
- [queue.php](../backend/config/queue.php)
- [bootstrap/app.php](../backend/bootstrap/app.php)
- [routes/console.php](../backend/routes/console.php)
- [NotificationAutomationTest.php](../backend/tests/Feature/NotificationAutomationTest.php)
- [kottraluy-queue.service](../deploy/systemd/kottraluy-queue.service)
- [kottraluy-scheduler.service](../deploy/systemd/kottraluy-scheduler.service)
- [kottraluy-scheduler.timer](../deploy/systemd/kottraluy-scheduler.timer)
- [kottraluy-health.service](../deploy/systemd/kottraluy-health.service)
- [kottraluy-health.timer](../deploy/systemd/kottraluy-health.timer)
- This operations report.

### References

Worker restart, retry and timeout behavior follows the installed Laravel 12 APIs
and [Laravel queue documentation](https://laravel.com/docs/12.x/queues).
Scheduling uses [Laravel's minute scheduler](https://laravel.com/docs/12.x/scheduling).
