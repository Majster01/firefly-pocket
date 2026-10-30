<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Exceptions\GeneralException;
use App\Http\Controllers\Base\BaseController;
use App\Services\BankConnectionService;
use Illuminate\Http\Request;


class SystemStatusController extends BaseController
{
    const DEFAULT_DAYS = 30;
    const MAX_DAYS = 365;

    public function get(Request $request)
    {
        BaseAuthorization::checkUser();
        $days = filter_var($request->query('days', self::DEFAULT_DAYS), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_DAYS]]);
        if ($days === false) {
            throw new GeneralException('days must be a number from 1 to ' . self::MAX_DAYS, BaseController::HTTP_CODE_UNPROCESSABLE_ENTITY);
        }
        return $this->respond(['data' => app(BankConnectionService::class)->getStatus($days)]);
    }
}
