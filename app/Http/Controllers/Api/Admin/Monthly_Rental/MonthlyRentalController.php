<?php

namespace App\Http\Controllers\Api\Admin\Monthly_Rental;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\occupant_monthly_payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonthlyRentalController extends BaseController
{
    //

    public function index(Request $request)
    {



        $filter = $request->all();
        $stall_no = $filter['stall_no'];
        $or_number = $filter['or_number'];




        $rawQuery = DB::table('occupant_monthly_payments')
            ->selectRaw('SUM(sections.rent_per_month) as total_monthly_payment')
            ->selectRaw('collectors.full_name')
            ->selectRaw('occupant_monthly_payments.*')
            ->join('occupants', 'occupants.stall_no', '=', 'occupant_monthly_payments.stall_no')
            ->join('sections', 'sections.id', '=', 'occupants.section_id')
            ->join('areas', 'areas.id', '=', 'sections.area_id')
            ->join('collectors', 'collectors.id', '=', 'occupants.collector_id')
            ->whereRaw("occupant_monthly_payments.stall_no = '" . $stall_no . "' AND occupant_monthly_payments.or_number = '" . $or_number . "'")
            ->groupBy('occupant_monthly_payments.id')
            ->get();
        // $Querydata = json_decode($rawQuery, true);

        if ($rawQuery->count() > 0) {
            return $this->sendResponse($rawQuery, 'Data retrieved successfully.');
        } else {
            return $this->sendResponse($rawQuery, 'Data not found.');
        }
    }

    public function update(Request $request, $id)
    {
        $data = occupant_monthly_payment::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }


}
