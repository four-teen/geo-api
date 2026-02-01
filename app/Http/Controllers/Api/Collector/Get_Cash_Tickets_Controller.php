<?php

namespace App\Http\Controllers\Api\Collector;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\cash_tickets;
use Illuminate\Http\Request;

class Get_Cash_Tickets_Controller extends BaseController
{
    //
    public function index(Request $request)
    {
        $filter = $request->all();

        $data = cash_tickets::all();

        return $this->sendResponse($data, 'Data retrieved successfully.');
    }

}
