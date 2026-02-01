<?php

namespace App\Http\Controllers\Api\Admin\Terminal;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\terminal_puv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class Puv extends BaseController
{
    //

    public function index(Request $request)
    {
        $filter = $request->all();

        $filter = $request->all();

        $rawQuery = DB::table('terminal_puvs')
            ->selectRaw('terminal_puvs.id')
            ->selectRaw('terminal_puvs.plate_number')
            ->selectRaw('terminal_puvs.owner')
            ->selectRaw('terminal_puvs.contact_no')
            ->selectRaw('terminal_puvs.make')
            ->selectRaw('terminal_cooperatives.name as cooperative_name')
            ->join('terminal_cooperatives', 'terminal_cooperatives.id', '=', 'terminal_puvs.cooperative_id')
            ->orderBy('terminal_puvs.id', 'DESC')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Data retrieved successfully.');
    }
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cooperative_id' => 'required',
            'plate_number' => 'required',
            'owner' => 'required',
            'contact_no' => 'required',
            'make' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();


        $area = terminal_puv::create($input);
        $success['name'] =  $area->name;

        return $this->sendResponse($success, 'Data created successfully.');
    }


    public function update(Request $request, $id)
    {
        $data = terminal_puv::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $data = terminal_puv::findOrFail($id);

        if ($data->null) {
            return $this->sendError('Data not found!', []);
        } else {
            $data->delete();
            return $this->sendResponse($data, 'Data deleted successfully.');
        }
    }
}
