<?php

namespace App\Http\Controllers\Api\Admin\Terminal;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\terminal_cooperative;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class Cooperatives extends BaseController
{
    //
    public function index(Request $request)
    {
        $filter = $request->all();

        $filter = $request->all();

        $rawQuery = DB::table('terminal_cooperatives')
            ->selectRaw('terminal_cooperatives.id')
            ->selectRaw('terminal_cooperatives.name')
            ->selectRaw('terminal_puv_types.name as terminal_puv_type_name')
            ->join('terminal_puv_types', 'terminal_puv_types.id', '=', 'terminal_cooperatives.terminal_puv_type_id')
            ->orderBy('terminal_cooperatives.id', 'DESC')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Data retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'terminal_puv_type_id' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();


        $area = terminal_cooperative::create($input);
        $success['name'] =  $area->name;

        return $this->sendResponse($success, 'Data created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = terminal_cooperative::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $data = terminal_cooperative::findOrFail($id);

        if ($data->null) {
            return $this->sendError('Data not found!', []);
        } else {
            $data->delete();
            return $this->sendResponse($data, 'Data deleted successfully.');
        }
    }
}
