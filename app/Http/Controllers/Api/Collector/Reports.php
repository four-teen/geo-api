<?php

namespace App\Http\Controllers\Api\Collector;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Reports extends BaseController
{
    //

    public function CollectorTotalCashTicketsPerDay(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];
        $id = $filter['id'];

        $rawQuery = DB::table('cash_tickets')
            ->selectRaw('SUM(cash_tickets.price) as total')
            ->join('dispense_tickets', 'dispense_tickets.cash_ticket_id', '=', 'cash_tickets.id')
            ->whereRaw("dispense_tickets.is_void = 0 AND dispense_tickets.collector_id ='" . $id . "' AND  dispense_tickets.created_at >= '" . $from . "' AND dispense_tickets.created_at < '" . $to . "'")
            // ->groupBy('cash_tickets.id')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        Log::channel('stderr')->info($Querydata);
        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }

    public function CashTicketsTransactions(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];
        $id = $filter['id'];

        $rawQuery = DB::table('cash_tickets')
            ->selectRaw('cash_tickets.name as cash_ticket_name')
            ->selectRaw('cash_tickets.price as cash_ticket_price')
            ->selectRaw('dispense_tickets.created_at')
            ->selectRaw('dispense_tickets.is_void')
            ->selectRaw('dispense_tickets.id')
             ->join('dispense_tickets', 'dispense_tickets.cash_ticket_id', '=', 'cash_tickets.id')
            ->whereRaw("dispense_tickets.collector_id ='" . $id . "' AND  dispense_tickets.created_at >= '" . $from . "' AND dispense_tickets.created_at < '" . $to . "'")
            ->groupBy('dispense_tickets.id')
            ->orderBy('dispense_tickets.created_at', 'DESC')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        Log::channel('stderr')->info($Querydata);
        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }
}
