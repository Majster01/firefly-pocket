<?php

namespace App\Validations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class PushValidation
{
    public static function validateSubscribe(Request $request)
    {
        $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'regex:#^https://#'],
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
        ]);
    }

    public static function validateUnsubscribe(Request $request)
    {
        $request->validate([
            'endpoint' => 'required|string|max:500',
        ]);
    }

    public static function makeNotifyValidator(Request $request)
    {
        return Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'body' => 'required|string|max:300',
            // The page opened on click: an app path, never another site
            'path' => ['nullable', 'string', 'regex:#^/(?!/)#'],
            'badge' => 'nullable|integer|min:0',
            'tag' => 'nullable|string|max:64',
        ]);
    }
}
