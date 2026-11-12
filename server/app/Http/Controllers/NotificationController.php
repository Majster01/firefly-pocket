<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Http\Controllers\Base\BaseController;
use App\Services\NotificationCenterService;
use App\Validations\NotificationValidation;
use Illuminate\Http\Request;


class NotificationController extends BaseController
{
    public function index(Request $request)
    {
        BaseAuthorization::checkUser();
        NotificationValidation::validatePage($request);
        $before = $request->query('before');
        $limit = $request->query('limit', NotificationValidation::DEFAULT_LIMIT);
        $page = app(NotificationCenterService::class)->page((string) getUserId(), $before === null ? null : (int) $before, (int) $limit);
        return $this->respond($page);
    }

    public function unreadCount(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['count' => app(NotificationCenterService::class)->unreadCount((string) getUserId())]);
    }

    public function markRead(Request $request, $id)
    {
        BaseAuthorization::checkUser();
        $service = app(NotificationCenterService::class);
        $userId = (string) getUserId();
        if (!$service->markRead($userId, (int) $id)) {
            return $this->setStatusCode(self::HTTP_CODE_NOT_FOUND)->respond(['message' => 'This notification is gone']);
        }
        return $this->respond(['unread' => $service->unreadCount($userId)]);
    }

    public function markAllRead(Request $request)
    {
        BaseAuthorization::checkUser();
        NotificationValidation::validateReadAll($request);
        $service = app(NotificationCenterService::class);
        $userId = (string) getUserId();
        $upTo = $request->input('up_to');
        $service->markAllRead($userId, $upTo === null ? null : (int) $upTo);
        return $this->respond(['unread' => $service->unreadCount($userId)]);
    }
}
