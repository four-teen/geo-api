<?php

namespace App\Http\Controllers\Api\Admin\Terminal;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\terminal_dispense_tickets;
use App\Models\terminal_routes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DispenseTicket extends BaseController
{
    //
    public function show( $id)
    {

        $rawQuery = DB::table('terminal_dispense_tickets')
            ->selectRaw('collectors.full_name')
            ->selectRaw('terminal_dispense_tickets.id')
            ->selectRaw("CONCAT(DATE_FORMAT(terminal_dispense_tickets.created_at, '%m%d%y'),'-',terminal_dispense_tickets.id) as transacation_code")
            ->selectRaw('terminal_dispense_tickets.*')
            ->selectRaw('terminal_routes.terminal')
            ->selectRaw('terminal_routes.route')
            ->selectRaw('terminal_puvs.plate_number')
            ->join('collectors', 'collectors.id', '=', 'terminal_dispense_tickets.collector_id')
            ->join('terminal_routes', 'terminal_routes.id', '=', 'terminal_dispense_tickets.terminal_id')
            ->join('terminal_puvs', 'terminal_puvs.id', '=', 'terminal_dispense_tickets.puv_id')
            ->whereRaw("terminal_dispense_tickets.id = '" . $id . "'")
            ->get();
        // $Querydata = json_decode($rawQuery, true);

        if ($rawQuery->count() > 0) {
            return $this->sendResponse($rawQuery, 'Data retrieved successfully.');
        } else {
            return $this->sendResponse($rawQuery, 'Data not found.');
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'terminal_id' => 'required',
            'puv_id' => 'required',
            'collector_id' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();

        $puv_id = $request->input('puv_id');
        $terminal_id = $request->input('terminal_id');

        $terminal = terminal_routes::findOrFail($terminal_id);
        $base_trip_tiket_fare = $terminal->base_trip_tiket_fare;
        $first_trip_tiket_fare = $terminal->first_trip_tiket_fare;

        // Check if passenger already has a ticket today
        $firstTripToday = !terminal_dispense_tickets::where('puv_id', $puv_id)
            ->whereDate('created_at', Carbon::today())
            ->exists();

        // Add ₱5 if first trip today
        $input['amount'] =  ($firstTripToday ? $first_trip_tiket_fare : $base_trip_tiket_fare);
        $input['is_first_trip'] =  ($firstTripToday ? 1 : 0);
        $Data = terminal_dispense_tickets::create($input);

        $success['amount'] =  $Data->amount;
        $success['created_at'] =  $Data->created_at;
        $success['id'] =  $Data->id;

        return $this->sendResponse($success, 'Data created successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = terminal_dispense_tickets::find($id);
        $data->update($request->all());
        return $this->sendResponse($data, 'Data updated successfully.');
    }

}
