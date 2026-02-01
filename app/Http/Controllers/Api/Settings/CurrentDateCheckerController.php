<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CurrentDateCheckerController extends BaseController
{
    //
    public function GetCurrentDate()
    {

        $currentDate = date("Y-m-d");
        return $currentDate;
    }
}
