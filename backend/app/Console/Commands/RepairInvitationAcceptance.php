<?php

namespace App\Console\Commands;

use App\Models\WorkspaceInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairInvitationAcceptance extends Command
{
    protected $signature = 'invitations:repair {--apply : Persist confirmed repairs; otherwise dry run}';
    protected $description = 'Repair missing acceptance timestamps using explicit accepted_user_id evidence only';

    public function handle(): int
    {
        $count = 0;
        WorkspaceInvitation::whereNull('accepted_at')->whereNotNull('accepted_user_id')
            ->orderBy('id')->chunkById(100, function ($invitations) use (&$count) {
                foreach ($invitations as $candidate) {
                    DB::transaction(function () use ($candidate, &$count) {
                        $invitation = WorkspaceInvitation::whereKey($candidate->id)->lockForUpdate()->first();
                        $user = $invitation?->acceptedUser()->lockForUpdate()->first();
                        // Contact similarity or membership alone is never acceptance evidence.
                        if (!$invitation || $invitation->accepted_at || $invitation->revoked_at || !$user
                            || $user->status !== 'active' || (int) $user->business_id !== (int) $invitation->business_id
                            || $user->job_role !== $invitation->role || $user->role !== 'staff'
                            || $user->created_at->lt($invitation->created_at)
                            || $user->created_at->gt($invitation->expires_at)) return;
                        $this->line("Confirmed invitation {$invitation->id}, accepting user {$user->id}");
                        // Historical time is unavailable: record the reconciliation time, not a guessed acceptance date.
                        if ($this->option('apply')) $invitation->forceFill(['accepted_at' => now()])->save();
                        $count++;
                    });
                }
            });
        $this->info(($this->option('apply') ? 'Repaired: ' : 'Dry run, eligible: ').$count);
        return self::SUCCESS;
    }
}
