<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\TelegramNotificationDelivery;

class NotificationsHealth extends Command
{
    protected $signature = 'notifications:health {--max-age=300 : Maximum heartbeat age in seconds}';
    protected $description = 'Check scheduler, queue, alert checks and failed deliveries without sending messages';

    public function handle(): int
    {
        $healthy = true;
        $age = max(60, (int) $this->option('max-age'));
        foreach (['scheduler', 'queue', 'inventory'] as $component) {
            $heartbeat = Cache::get('operations:'.$component.'-heartbeat');
            $ok = $heartbeat && now()->timestamp - $heartbeat <= $age;
            $this->line($component.': '.($ok ? 'healthy' : 'missing or stale'));
            $healthy = $healthy && $ok;
        }
        $driver = config('queue.default');
        $this->line('queue connection: '.$driver);
        $healthy = $healthy && !in_array($driver, ['sync', 'null', 'deferred', 'background'], true);
        $failed = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->count();
        $rejected = TelegramNotificationDelivery::where('status', 'failed')->count();
        $stale = TelegramNotificationDelivery::where('status', 'pending')->where('updated_at', '<', now()->subHours(2))->count();
        $this->line("failed jobs: {$failed}; failed deliveries: {$rejected}; pending deliveries older than 2 hours: {$stale}");
        return $healthy && !$failed && !$rejected && !$stale ? self::SUCCESS : self::FAILURE;
    }
}
