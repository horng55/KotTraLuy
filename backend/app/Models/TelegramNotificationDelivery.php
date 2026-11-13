<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramNotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id', 'user_id', 'business_id', 'status', 'attempts',
        'telegram_message_id', 'last_error', 'next_attempt_at', 'sent_at',
    ];

    protected $casts = [
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function notification() { return $this->belongsTo(WorkspaceNotification::class, 'notification_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
