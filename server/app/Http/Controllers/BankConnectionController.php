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

    public function import(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(BankConnectionService::class)->startImport($request->name, $request->date_from, $request->date_to, $this->psu($request))]);
    }

    /**
     * Who is tapping "Import": their public IP and browser, which the importer hands to the bank as PSU-* headers.
     * A request made by a person is attended, so the bank does not count it against its small daily quota for background access.
     * Behind Caddy the peer is a container, so the address is the one Caddy put last into X-Forwarded-For; it is only ever used for this user's own import.
     */
    private function psu(Request $request): array
    {
        $candidates = array_reverse(array_map('trim', explode(',', (string) $request->header('X-Forwarded-For'))));
        $candidates[] = (string) $request->ip();
        $ip = collect($candidates)->first(fn($candidate) => filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);

        return array_filter(['ip_address' => $ip, 'user_agent' => $request->userAgent()]);
    }

    public function delete(Request $request)
    {
        BaseAuthorization::checkUser();
        app(BankConnectionService::class)->delete($request->name);
        return $this->respond(['data' => null]);
    }
}
