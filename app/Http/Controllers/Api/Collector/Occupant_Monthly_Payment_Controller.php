<?php

namespace App\Http\Controllers\Api\Collector;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\occupant_monthly_payment;
use Faker\Provider\Base;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Occupant_Monthly_Payment_Controller extends BaseController
{
    //

    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'stall_no' => 'required',
            'or_number' => 'required',
            'paid_date' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();


        $area = occupant_monthly_payment::create($input);
        $success['stall_no'] =  $input['stall_no'];
        $success['or_number'] =  $input['or_number'];
        $success['paid_date'] =  $input['paid_date'];
        $success['created_at'] =  $area->created_at;


        return $this->sendResponse($success, 'Area created successfully.');
    }
}
