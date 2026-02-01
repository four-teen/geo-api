<?php

namespace App\Http\Controllers\Api\Collector;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\dispense_tickets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Dispense_Cash_Tickets_Controller extends BaseController
{
    //


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'collector_id' => 'required',
            'cash_ticket_id' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();


        $area = dispense_tickets::create($input);
        $success['id'] =  $area->id;
        $success['name'] =  $input['name'];
        $success['price'] =  $input['price'];
        $success['created_at'] =  $area->created_at;

        return $this->sendResponse($success, 'Area created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = dispense_tickets::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }
}
