<?php

namespace App\Validations;

use App\Services\Push\PushPreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


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

    public static function validateEndpoint(Request $request)
    {
        $request->validate(['endpoint' => 'required|string|max:500']);
    }

    public static function validatePreferences(Request $request)
    {
        $request->validate([
            'endpoint' => 'required|string|max:500',
            'preferences' => 'required|array',
            'preferences.import_success' => 'boolean',
            'preferences.import_success_only_new' => 'boolean',
            'preferences.import_failure' => 'boolean',
            'preferences.upgrade_success' => 'boolean',
            'preferences.suggestions' => 'array',
            'preferences.suggestions.frequency' => ['string', Rule::in(PushPreferences::FREQUENCIES)],
            'preferences.suggestions.weekday' => 'integer|min:1|max:7',
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
            // What kind of message this is: decides which devices get it. No event = an alert, which everyone gets
            'event' => ['nullable', 'string', Rule::in(PushPreferences::EVENTS)],
            // New transactions of an import
            'count' => 'nullable|integer|min:0',
        ]);
    }
}
