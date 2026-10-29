<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Http\Controllers\Base\BaseController;
use App\Services\BankConnectionService;
use Illuminate\Http\Request;


class BankConnectionController extends BaseController
{
    public function getAll(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(BankConnectionService::class)->getAll()]);
    }

    public function reauthenticate(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(BankConnectionService::class)->reauthenticate($request->name)]);
    }

    public function delete(Request $request)
    {
        BaseAuthorization::checkUser();
        app(BankConnectionService::class)->delete($request->name);
        return $this->respond(['data' => null]);
    }
}
