<?php

namespace App\Http\Controllers\Api\Admin\Occupants;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Occupants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OccupantsController extends BaseController
{
    //

    public function index(Request $request)
    {
        $filter = $request->all();

        $rawQuery = DB::table('occupants')
            ->selectRaw('occupants.id')
            ->selectRaw('occupants.stall_no')
            ->selectRaw('occupants.awardee_name')
            ->selectRaw('occupants.occupant_name')
            ->selectRaw('occupants.is_rentee')
            ->selectRaw('occupants.is_with_business_permit')
            ->selectRaw('occupants.is_with_water_electricity')
            ->selectRaw('occupants.is_active')
            ->selectRaw('occupants.remarks')
            ->selectRaw('areas.name as area_name')
            ->selectRaw('sections.name as section_name')
            ->selectRaw('sections.rent_per_month')
            ->selectRaw('collectors.full_name as collector_name')
            ->join('collectors', 'collectors.id', '=', 'occupants.collector_id')
            ->join('sections', 'sections.id', '=', 'occupants.section_id')
            ->join('areas', 'areas.id', '=', 'sections.area_id')
            ->orderBy('occupants.stall_no', 'ASC')
            // ->selectRaw('sections.id as section_id')
            // ->selectRaw('sections.name as section_name')
            // ->selectRaw('sections.rent_per_month')
            // ->join('sections', 'sections.area_id', '=', 'areas.id')
            // ->selectRaw('COUNT(DISTINCT bets.transactionId) as TotalVoidCount')
            // ->selectRaw('SUM(bets.betAmount) As totalVoid')
            // ->leftJoin('draws', 'draws.id', '=', 'bets.drawId')
            // ->leftJoin('tellers', 'tellers.id', '=', 'bets.tellerId')
            // // ->where('draws.created_at', '>=', $from)
            // // ->where('draws.created_at', '<', $to)
            // ->where('bets.isVoid', '=', 1)
            // ->groupBy('tellers.id', 'draws.id')
            // ->groupBy('tellers.id')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'stall_no' => 'required',
            'awardee_name' => 'required',
            'occupant_name' => 'required',
            'is_rentee' => 'required',
            'is_with_business_permit' => 'required',
            'is_with_water_electricity' => 'required',
            'section_id' => 'required',
            // 'is_active' => 'required',
            'collector_id' => 'required',
            // 'remarks' => 'required',

        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();


        $return_data = Occupants::create($input);
        // $success['name'] =  $area->name;

        return $this->sendResponse($return_data, 'Area created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = Occupants::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }


    public function destroy(Request $request, $id)
    {
        $data = Occupants::findOrFail($id);

        if ($data->null) {
            return $this->sendError('Data not found!', []);
        } else {
            $data->delete();
            return $this->sendResponse($data, 'Data deleted successfully.');
        }
    }
}
