<?php

namespace App\Models;


class InboxNotificationRead extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'notification_id', 'read_at',
    ];
}
