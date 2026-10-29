<?php

namespace App\Validations;

use App\Services\RuleSuggestionService;
use Illuminate\Http\Request;


class RuleSuggestionValidation
{
    public static function validatePreview(Request $request)
    {
        self::triggersValidation($request);
    }

    public static function validateApprove(Request $request)
    {
        self::categoryValidation($request);
        self::triggersValidation($request);
    }

    public static function validateCategorize(Request $request)
    {
        self::categoryValidation($request);
    }

    private static function categoryValidation(Request $request)
    {
        $request->validate([
            'category' => 'required|string|max:255',
        ]);
    }

    private static function triggersValidation(Request $request)
    {
        $request->validate([
            'triggers' => 'required|array|min:1',
            'triggers.*.type' => 'required|in:' . implode(',', RuleSuggestionService::TRIGGER_TYPES),
            'triggers.*.value' => 'required|string|max:255',
        ]);
    }
}
