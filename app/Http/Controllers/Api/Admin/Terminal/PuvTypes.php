<?php

namespace App\Http\Controllers\Api\Admin\Terminal;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\terminal_puv_type;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PuvTypes extends BaseController
{
    //
    public function index(Request $request)
    {
        $filter = $request->all();

        $data = terminal_puv_type::all();

        return $this->sendResponse($data, 'Data retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();


        $area = terminal_puv_type::create($input);
        $success['name'] =  $area->name;

        return $this->sendResponse($success, 'Data created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = terminal_puv_type::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $data = terminal_puv_type::findOrFail($id);

        if ($data->null) {
            return $this->sendError('Data not found!', []);
        } else {
            $data->delete();
            return $this->sendResponse($data, 'Data deleted successfully.');
        }
    }
}
