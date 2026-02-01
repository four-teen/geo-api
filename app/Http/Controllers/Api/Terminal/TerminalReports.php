<?php

namespace App\Http\Controllers\Api\Terminal;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TerminalReports extends BaseController
{
    //

    public function OverAllDispenseTerminalTickets(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];

        $rawQuery = DB::table('terminal_dispense_tickets')
            ->selectRaw('SUM(terminal_dispense_tickets.amount) as total_dispensed')
            ->whereRaw("terminal_dispense_tickets.is_void = 0 AND  terminal_dispense_tickets.created_at >= '" . $from . "' AND terminal_dispense_tickets.created_at < '" . $to . "'")
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }

    public function TerminalTotalTicketsPerDay(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];
        $id = $filter['id'];

        $rawQuery = DB::table('terminal_dispense_tickets')
            ->selectRaw('SUM(terminal_dispense_tickets.amount) as total')
            ->whereRaw("terminal_dispense_tickets.is_void = 0 AND terminal_dispense_tickets.collector_id ='" . $id . "' AND  terminal_dispense_tickets.created_at >= '" . $from . "' AND terminal_dispense_tickets.created_at < '" . $to . "'")
            // ->groupBy('cash_tickets.id')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        // Log::channel('stderr')->info($filter);
        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }
}
