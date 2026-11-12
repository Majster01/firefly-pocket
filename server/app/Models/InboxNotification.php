<?php

namespace App\Models;


class InboxNotification extends BaseModel
{
    protected $fillable = [
        'kind', 'title', 'body', 'path', 'tag', 'count', 'quiet',
    ];

    protected $casts = [
        'quiet' => 'boolean',
        'count' => 'integer',
    ];
}
