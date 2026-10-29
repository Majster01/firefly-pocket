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

    public function getOne(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(BankConnectionService::class)->getOne($request->name)]);
    }

    public function getBanks(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(BankConnectionService::class)->getBanks($request->query('country'))]);
    }

    public function create(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(BankConnectionService::class)->create($request->country, $request->bank, $request->psu_type ?? 'personal')]);
    }

    public function saveAccounts(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(BankConnectionService::class)->saveAccounts($request->name, $request->accounts, $request->import_since)]);
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
