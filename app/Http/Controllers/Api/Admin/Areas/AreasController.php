<?php

namespace App\Http\Controllers\Api\Admin\Areas;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Areas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AreasController extends BaseController
{
    //

    public function index(Request $request)
    {
        $filter = $request->all();

        $data = Areas::all();

        return $this->sendResponse($data, 'Supervisor retrieved successfully.');
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


        $area = Areas::create($input);
        $success['name'] =  $area->name;

        return $this->sendResponse($success, 'Area created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = Areas::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $data = Areas::findOrFail($id);

        if ($data->null) {
            return $this->sendError('Data not found!', []);
        } else {
            $data->delete();
            return $this->sendResponse($data, 'Data deleted successfully.');
        }
    }
}
