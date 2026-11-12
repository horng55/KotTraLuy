<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;

class WorkspaceNotification extends DatabaseNotification
{
    protected $table = 'notifications';
}
