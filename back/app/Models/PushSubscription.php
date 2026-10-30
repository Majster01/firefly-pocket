<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class PushSubscription extends BaseModel
{
    protected $fillable = [
        'user_id', 'endpoint', 'p256dh', 'auth', 'user_agent',
    ];

    public function scopeAllowed(Builder $query)
    {
        return $query->where('user_id', getUserId());
    }
}
