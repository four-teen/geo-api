<?php

namespace App\Http\Controllers\Api\Admin\Cash_Tickets;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\cash_tickets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Cash_TicketsController extends BaseController
{
    //

    public function index(Request $request)
    {
        $filter = $request->all();

        $data = cash_tickets::all();

        return $this->sendResponse($data, 'Supervisor retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'price' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();


        $area = cash_tickets::create($input);
        $success['name'] =  $area->name;

        return $this->sendResponse($success, 'Data created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = cash_tickets::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $data = cash_tickets::findOrFail($id);

        if ($data->null) {
            return $this->sendError('Data not found!', []);
        } else {
            $data->delete();
            return $this->sendResponse($data, 'Data deleted successfully.');
        }
    }

}
