<?php

namespace App\Validations;

use Illuminate\Http\Request;


class NotificationValidation
{
    const DEFAULT_LIMIT = 20;
    const MAX_LIMIT = 50;

    public static function validatePage(Request $request)
    {
        $request->validate([
            'before' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ]);
    }

    public static function validateReadAll(Request $request)
    {
        $request->validate(['up_to' => 'nullable|integer|min:0']);
    }
}
