<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Http\Controllers\Base\BaseController;
use Illuminate\Http\Request;


class UserController extends BaseController
{


    public function getUser(Request $request)
    {
        // A refused token is a 401, never a 200 with an empty body: the web app tells the two apart by this
        BaseAuthorization::checkUser();
        return getUser();
    }


}
