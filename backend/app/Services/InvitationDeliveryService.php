<?php

namespace App\Services;

use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class InvitationDeliveryService
{
    public function deliver(WorkspaceInvitation $invitation, string $shareUrl): string
    {
        $message = "You have been invited to join {$invitation->business->name} on KotTraLuy.\n\nOpen this secure invitation link before it expires:\n{$shareUrl}";
        try {
            if ($invitation->channel === 'email' && config('services.invitation.email_provider')) {
                Mail::raw($message, function ($mail) use ($invitation) {
                    $mail->to($invitation->email)->subject('KotTraLuy workspace invitation');
                });
                return 'sent';
            }
            if ($invitation->channel === 'phone' && config('services.invitation.sms_webhook_url')) {
                $response = Http::timeout(10)->post(config('services.invitation.sms_webhook_url'), [
                    'to' => $invitation->phone, 'message' => $message, 'invitation_url' => $shareUrl,
                ]);
                if (!$response->successful()) throw new \RuntimeException('SMS provider rejected the invitation.');
                return 'sent';
            }
            return 'manual';
        } catch (\Throwable $exception) {
            $invitation->forceFill(['delivery_status' => 'failed', 'delivery_error' => substr($exception->getMessage(), 0, 1000)])->save();
            return 'failed';
        }
    }
}
