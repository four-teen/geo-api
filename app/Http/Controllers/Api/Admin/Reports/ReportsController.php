<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends BaseController
{
    //
    public function GetAreaAndSection(Request $request)
    {


        $filter = $request->all();

        $rawQuery = DB::table('areas')
            ->selectRaw('areas.id as area_id')
            ->selectRaw('areas.name as area_name')
            ->selectRaw('sections.id as section_id')
            ->selectRaw('sections.name as section_name')
            ->selectRaw('sections.rent_per_month')
            ->join('sections', 'sections.area_id', '=', 'areas.id')
            // ->selectRaw('COUNT(DISTINCT bets.transactionId) as TotalVoidCount')
            // ->selectRaw('SUM(bets.betAmount) As totalVoid')
            // ->leftJoin('draws', 'draws.id', '=', 'bets.drawId')
            // ->leftJoin('tellers', 'tellers.id', '=', 'bets.tellerId')
            // // ->where('draws.created_at', '>=', $from)
            // // ->where('draws.created_at', '<', $to)
            // ->groupBy('tellers.id', 'draws.id')
            // ->groupBy('tellers.id')
            ->orderBy('areas.name', 'ASC')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }


    public function OverAllDispenseCashTickets(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];

        $rawQuery = DB::table('cash_tickets')
            ->selectRaw('SUM(cash_tickets.price) as total_dispensed')
            ->join('dispense_tickets', 'dispense_tickets.cash_ticket_id', '=', 'cash_tickets.id')
            ->whereRaw("dispense_tickets.is_void = 0 AND  dispense_tickets.created_at >= '" . $from . "' AND dispense_tickets.created_at < '" . $to . "'")
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }

    public function OverAllDispenseCashTicketsPerName(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];

        $rawQuery = DB::table('cash_tickets')
            ->selectRaw('SUM(cash_tickets.price) as total_dispensed')
            ->selectRaw('cash_tickets.name')
            ->join('dispense_tickets', 'dispense_tickets.cash_ticket_id', '=', 'cash_tickets.id')
            ->whereRaw("dispense_tickets.is_void = 0 AND  dispense_tickets.created_at >= '" . $from . "' AND dispense_tickets.created_at < '" . $to . "'")
            ->groupBy('cash_tickets.id')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }

    public function OverAllDispenseCashTicketsPerCollector(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];

        $rawQuery = DB::table('cash_tickets')
            ->selectRaw('SUM(cash_tickets.price) as total_dispensed')
            ->selectRaw('cash_tickets.name')
            ->selectRaw('collectors.full_name')
            ->join('dispense_tickets', 'dispense_tickets.cash_ticket_id', '=', 'cash_tickets.id')
            ->join('collectors', 'collectors.id', '=', 'dispense_tickets.collector_id')
            ->whereRaw("dispense_tickets.is_void = 0 AND  dispense_tickets.created_at >= '" . $from . "' AND dispense_tickets.created_at < '" . $to . "'")
            ->groupBy('collectors.id')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }


    public function OverAllMonthlyPayment(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];

        $rawQuery = DB::table('occupant_monthly_payments')
            ->selectRaw('SUM(sections.rent_per_month) as total_monthly_payment')
            ->join('occupants', 'occupants.stall_no', '=', 'occupant_monthly_payments.stall_no')
            ->join('sections', 'sections.id', '=', 'occupants.section_id')
            ->whereRaw("occupant_monthly_payments.is_void = 0 AND  occupant_monthly_payments.created_at >= '" . $from . "' AND occupant_monthly_payments.created_at < '" . $to . "'")
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }

    public function OverAllMonthlyPaymentPerArea(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];

        $rawQuery = DB::table('occupant_monthly_payments')
            ->selectRaw('SUM(sections.rent_per_month) as total_monthly_payment')
            ->selectRaw('areas.name as area_name')
            ->join('occupants', 'occupants.stall_no', '=', 'occupant_monthly_payments.stall_no')
            ->join('sections', 'sections.id', '=', 'occupants.section_id')
            ->join('areas', 'areas.id', '=', 'sections.area_id')
            ->whereRaw("occupant_monthly_payments.is_void = 0 AND  occupant_monthly_payments.created_at >= '" . $from . "' AND occupant_monthly_payments.created_at < '" . $to . "'")
            ->groupBy('areas.id')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }


    public function OverAllMonthlyPaymentPerCollector(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];

        $rawQuery = DB::table('occupant_monthly_payments')
            ->selectRaw('SUM(sections.rent_per_month) as total_monthly_payment')
            ->selectRaw('collectors.full_name')
            ->join('occupants', 'occupants.stall_no', '=', 'occupant_monthly_payments.stall_no')
            ->join('sections', 'sections.id', '=', 'occupants.section_id')
            ->join('areas', 'areas.id', '=', 'sections.area_id')
            ->join('collectors', 'collectors.id', '=', 'occupants.collector_id')
            ->whereRaw("occupant_monthly_payments.is_void = 0 AND  occupant_monthly_payments.created_at >= '" . $from . "' AND occupant_monthly_payments.created_at < '" . $to . "'")
            ->groupBy('collectors.id')
            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }



    public function MontlyRentalReports(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];

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
            ->selectRaw('CASE WHEN occupant_monthly_payments.stall_no  IS NULL THEN "Unpaid" ELSE "Paid" END as payment_status')
            ->selectRaw('CASE WHEN occupant_monthly_payments.or_number  IS NULL THEN "N/A" ELSE occupant_monthly_payments.or_number END as or_number')
            ->selectRaw('CASE WHEN occupant_monthly_payments.paid_date  IS NULL THEN "N/A" ELSE occupant_monthly_payments.paid_date END as paid_date')
            ->join('collectors', 'collectors.id', '=', 'occupants.collector_id')
            ->join('sections', 'sections.id', '=', 'occupants.section_id')
            ->join('areas', 'areas.id', '=', 'sections.area_id')
            ->leftJoin('occupant_monthly_payments', function ($join) use ($from, $to) {
                $join->on('occupant_monthly_payments.stall_no', '=', 'occupants.stall_no')
                    ->whereRaw("occupant_monthly_payments.paid_date >= '" . $from . "' AND occupant_monthly_payments.paid_date < '" . $to . "'");
            })
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

    public function MontlyRentalReportsChecker(Request $request)
    {


        $filter = $request->all();
        $from = $filter['from'];
        $to = $filter['to'];
        $stall_no = $filter['stall_no'];

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
            ->selectRaw('CASE WHEN occupant_monthly_payments.stall_no  IS NULL THEN "Unpaid" ELSE "Paid" END as payment_status')
            ->selectRaw('CASE WHEN occupant_monthly_payments.or_number  IS NULL THEN "N/A" ELSE occupant_monthly_payments.or_number END as or_number')
            ->selectRaw('CASE WHEN occupant_monthly_payments.paid_date  IS NULL THEN "N/A" ELSE occupant_monthly_payments.paid_date END as paid_date')
            ->join('collectors', 'collectors.id', '=', 'occupants.collector_id')
            ->join('sections', 'sections.id', '=', 'occupants.section_id')
            ->join('areas', 'areas.id', '=', 'sections.area_id')
            ->leftJoin('occupant_monthly_payments', 'occupant_monthly_payments.stall_no', '=', 'occupants.stall_no')
            // ->leftJoin('occupant_monthly_payments', function ($join) use ($from, $to, $stall_no) {
            //     $join->on('occupant_monthly_payments.stall_no', '=', 'occupants.stall_no')
            //          ->whereRaw("occupant_monthly_payments.stall_no = '".$stall_no."' AND occupant_monthly_payments.paid_date >= '" . $from . "' AND occupant_monthly_payments.paid_date < '" . $to . "'");
            // })
            ->whereRaw("occupant_monthly_payments.stall_no = '".$stall_no."' AND occupant_monthly_payments.paid_date >= '" . $from . "' AND occupant_monthly_payments.paid_date < '" . $to . "'")
            ->groupBy('occupant_monthly_payments.paid_date')
            ->orderBy('occupant_monthly_payments.paid_date', 'ASC')
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
            // ->groupBy('tellers.id', 'draws.id')

            ->get();
        $Querydata = json_decode($rawQuery, true);

        return $this->sendResponse($Querydata, 'Reports retrieved successfully.');
    }
}
