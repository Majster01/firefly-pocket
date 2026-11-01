<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Http\Controllers\Base\BaseController;
use App\Services\PushNotificationService;
use App\Validations\PushValidation;
use Illuminate\Http\Request;


class PushController extends BaseController
{
    public function getPublicKey(Request $request)
    {
        BaseAuthorization::checkUser();
        $service = app(PushNotificationService::class);
        return $this->respond(['data' => ['configured' => $service->isConfigured(), 'public_key' => $service->getPublicKey()]]);
    }

    public function subscribe(Request $request)
    {
        BaseAuthorization::checkUser();
        PushValidation::validateSubscribe($request);
        app(PushNotificationService::class)->subscribe(getUserId(), $request->endpoint, $request->input('keys.p256dh'), $request->input('keys.auth'), $request->userAgent());
        return $this->respond(['data' => null]);
    }

    public function unsubscribe(Request $request)
    {
        BaseAuthorization::checkUser();
        PushValidation::validateUnsubscribe($request);
        app(PushNotificationService::class)->unsubscribe($request->endpoint);
        return $this->respond(['data' => null]);
    }

    public function getPreferences(Request $request)
    {
        BaseAuthorization::checkUser();
        PushValidation::validateEndpoint($request);
        $preferences = app(PushNotificationService::class)->preferences($request->query('endpoint'));
        return $preferences === null ? $this->notSubscribed() : $this->respond(['data' => $preferences]);
    }

    public function savePreferences(Request $request)
    {
        BaseAuthorization::checkUser();
        PushValidation::validatePreferences($request);
        $preferences = app(PushNotificationService::class)->savePreferences($request->endpoint, $request->input('preferences'));
        return $preferences === null ? $this->notSubscribed() : $this->respond(['data' => $preferences]);
    }

    private function notSubscribed()
    {
        return $this->setStatusCode(self::HTTP_CODE_NOT_FOUND)->respond(['message' => 'This device is not subscribed to notifications']);
    }
}
