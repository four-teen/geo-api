<?php

namespace App\Http\Controllers\Api\Admin\Sections;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Sections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SectionController extends BaseController
{
    //
    public function index(Request $request)
    {
        $filter = $request->all();

        $data = DB::table('sections')
        ->selectRaw('sections.*')
        ->selectRaw('areas.name as area_name')
        ->leftJoin('areas', 'areas.id', '=', 'sections.area_id')
        ->get();

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


        $area = Sections::create($input);
        $success['name'] =  $area->name;

        return $this->sendResponse($success, 'Area created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = Sections::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $data = Sections::findOrFail($id);

        if ($data->null) {
            return $this->sendError('Data not found!', []);
        } else {
            $data->delete();
            return $this->sendResponse($data, 'Data deleted successfully.');
        }
    }
}
