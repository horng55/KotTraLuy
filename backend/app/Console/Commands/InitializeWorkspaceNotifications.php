<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\WorkspaceNotificationSetup;
use Illuminate\Console\Command;

class InitializeWorkspaceNotifications extends Command
{
    protected $signature = 'notifications:initialize-workspaces {--dry-run : Report missing defaults without changing data}';
    protected $description = 'Idempotently fill missing workspace notification preferences and owner recipients';

    public function handle(WorkspaceNotificationSetup $setup): int
    {
        $changed = 0;
        foreach (Business::orderBy('id')->lazyById(100) as $business) {
            if ($this->option('dry-run')) {
                $required = ['telegram_enabled', 'low_stock', 'out_of_stock', 'expiring_soon', 'expired', 'reminder_frequency_hours', 'preferred_language', 'recipients'];
                $missing = array_diff($required, array_keys((array) $business->notification_preferences));
                if ($missing) {
                    $changed++;
                    $this->line('Workspace '.$business->id.': missing '.implode(', ', $missing));
                }
            } elseif ($setup->initialize($business)) {
                $changed++;
            }
        }
        $this->info($changed.' workspace(s) '.($this->option('dry-run') ? 'need initialization.' : 'initialized.'));
        return self::SUCCESS;
    }
}
