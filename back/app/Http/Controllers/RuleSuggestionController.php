<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Http\Controllers\Base\BaseController;
use App\Services\RuleSuggestionService;
use App\Validations\RuleSuggestionValidation;
use Illuminate\Http\Request;


class RuleSuggestionController extends BaseController
{
    public function getAll(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(RuleSuggestionService::class)->getAll()]);
    }

    public function getOne(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(RuleSuggestionService::class)->getOne($request->id)]);
    }

    public function preview(Request $request)
    {
        BaseAuthorization::checkUser();
        RuleSuggestionValidation::validatePreview($request);
        return $this->respond(['data' => app(RuleSuggestionService::class)->preview($request->triggers)]);
    }

    public function approve(Request $request)
    {
        BaseAuthorization::checkUser();
        RuleSuggestionValidation::validateApprove($request);
        return $this->respond(['data' => app(RuleSuggestionService::class)->approve($request->id, $request->category, $request->triggers)]);
    }

    public function categorize(Request $request)
    {
        BaseAuthorization::checkUser();
        RuleSuggestionValidation::validateCategorize($request);
        return $this->respond(['data' => app(RuleSuggestionService::class)->categorize($request->id, $request->category)]);
    }

    public function delete(Request $request)
    {
        BaseAuthorization::checkUser();
        app(RuleSuggestionService::class)->reject($request->id);
        return $this->respond(['data' => null]);
    }
}
