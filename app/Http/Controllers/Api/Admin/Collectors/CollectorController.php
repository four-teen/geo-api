<?php

namespace App\Http\Controllers\Api\Admin\Collectors;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Collectors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CollectorController extends BaseController
{
    //

    public function index(Request $request)
    {
        $filter = $request->all();

        $data = Collectors::all();

        return $this->sendResponse($data, 'Supervisor retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required',
            'username' => 'required',
            'password' => 'required',
        ]);


        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();

        $input['password'] = bcrypt($input['password']);
        $area = Collectors::create($input);
        $success['name'] =  $area->name;

        return $this->sendResponse($success, 'Area created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = Collectors::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $data = Collectors::findOrFail($id);

        if ($data->null) {
            return $this->sendError('Data not found!', []);
        } else {
            $data->delete();
            return $this->sendResponse($data, 'Data deleted successfully.');
        }
    }


}
