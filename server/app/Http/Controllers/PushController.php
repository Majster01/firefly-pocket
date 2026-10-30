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
}
