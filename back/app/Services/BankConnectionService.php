<?php

namespace App\Services;

use App\Exceptions\GeneralException;
use App\Http\Controllers\Base\BaseController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Bank connections (Enable Banking consents) live in the FireflyX fork of the Firefly III Data Importer.
 * Pico lists them, adds new ones, maps their accounts and starts re-authentication; the contract is the fork's /api/fireflyx/v1.
 */
class BankConnectionService
{
    // Where the bank sends the user back to, via the importer. A new connection continues on the setup page.
    const RETURN_PATH = '/bank-connections/list';

    public function getAll()
    {
        return $this->importer('get', 'connections')['data'];
    }

    public function getOne($name)
    {
        return $this->importer('get', "connections/$name")['data'];
    }

    public function getBanks($country)
    {
        return $this->importer('get', 'banks', ['country' => $country])['data'];
    }

    /**
     * Starts the consent for a bank without a connection yet and returns the connection name and the URL to send the user to.
     */
    public function create($country, $bank, $psuType)
    {
        return $this->importer('post', 'connections', ['country' => $country, 'bank' => $bank, 'psu_type' => $psuType, 'return_path' => self::RETURN_PATH])['data'];
    }

    /**
     * Which Firefly account each bank account feeds ({id, firefly_account_id}, null = not imported) and how far back the first import reaches.
     */
    public function saveAccounts($name, $accounts, $importSince)
    {
        return $this->importer('put', "connections/$name/accounts", ['accounts' => $accounts, 'import_since' => $importSince])['data'];
    }

    /**
     * Starts a new consent at the bank and returns the URL to send the user to.
     */
    public function reauthenticate($name)
    {
        return $this->importer('post', "connections/$name/reauth", ['return_path' => self::RETURN_PATH])['data'];
    }

    public function delete($name)
    {
        $this->importer('delete', "connections/$name", [], BaseController::HTTP_CODE_OK_DELETE);
    }

    private function importer($method, $path, $data = [], $expectedStatus = BaseController::HTTP_CODE_OK)
    {
        if (!config('app.importer_api_url') || !config('app.importer_api_token')) {
            throw new GeneralException('Bank connections are not configured (IMPORTER_API_URL, IMPORTER_API_TOKEN)', BaseController::HTTP_CODE_INTERNAL_SERVER_ERROR);
        }
        try {
            // Listing banks and starting a consent call the bank's API => allow more than a quick call
            $response = Http::withToken(config('app.importer_api_token'))->acceptJson()->timeout(60)
                ->$method(rtrim(config('app.importer_api_url'), '/') . "/$path", $data);
        } catch (ConnectionException $e) {
            throw new GeneralException('The importer is not reachable', BaseController::HTTP_CODE_INTERNAL_SERVER_ERROR);
        }
        if ($response->status() !== $expectedStatus) {
            $status = $response->status() === BaseController::HTTP_CODE_NOT_FOUND ? BaseController::HTTP_CODE_NOT_FOUND : BaseController::HTTP_CODE_UNPROCESSABLE_ENTITY;
            throw new GeneralException($response->json('error') ?? 'The importer answered ' . $response->status(), $status);
        }
        return $response->json();
    }
}
