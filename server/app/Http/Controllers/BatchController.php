<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Exceptions\GeneralException;
use App\Http\Controllers\Base\BaseController;
use App\Services\BatchReader;
use App\Services\CacheWarmUp;
use Illuminate\Http\Request;

/**
 * POST /api/batch {paths: ["insight/expense/total?start=…", …], page: "/"}: several Firefly reads in one request
 * (BatchReader). Each answer has its own status; the web app asks again on its own for any that is not a 200.
 */
class BatchController extends BaseController
{
    public function read(Request $request)
    {
        BaseAuthorization::checkUser();
        $paths = $request->input('paths');
        if (!is_array($paths) || !$paths || count($paths) > BatchReader::MAX_PATHS || array_filter($paths, fn($path) => !is_string($path) || strlen($path) > 2000)) {
            throw new GeneralException('paths: 1 to ' . BatchReader::MAX_PATHS . ' paths', self::HTTP_CODE_UNPROCESSABLE_ENTITY);
        }

        $answers = app(BatchReader::class)->read($request, $paths);
        // What Home reads is fetched again after the next import (CacheWarmUp)
        if ($request->input('page') === '/') {
            app(CacheWarmUp::class)->remember($request, array_keys(array_filter($answers, fn($answer) => $answer['status'] === 200)));
        }
        return response(BatchReader::toJson($answers), 200, ['Content-Type' => 'application/json']);
    }
}
