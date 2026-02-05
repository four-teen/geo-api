<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

//Admin
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\Areas\AreasController;
use App\Http\Controllers\Api\Admin\LivestockCharges\LivestockChargesController;
use App\Http\Controllers\Api\Admin\SlaughterPrivate\SLPrivate;
use App\Http\Controllers\Api\Admin\Cash_Tickets\Cash_TicketsController;
use App\Http\Controllers\Api\Admin\Collectors\CollectorController;
use App\Http\Controllers\Api\Admin\Monthly_Rental\MonthlyRentalController;
use App\Http\Controllers\Api\Admin\Occupants\OccupantsController;
use App\Http\Controllers\Api\Admin\Reports\ReportsController;
use App\Http\Controllers\Api\Admin\Sections\SectionController;
use App\Http\Controllers\Api\Admin\Terminal\Cooperatives;
use App\Http\Controllers\Api\Admin\Terminal\DispenseTicket;
use App\Http\Controllers\Api\Admin\Terminal\Puv;
use App\Http\Controllers\Api\Admin\Terminal\PuvTypes;
use App\Http\Controllers\Api\Admin\Terminal\TerminalRoutes;
use App\Http\Controllers\Api\Collector\CollectorLoginController;
use App\Http\Controllers\Api\Collector\Dispense_Cash_Tickets_Controller;
use App\Http\Controllers\Api\Collector\Get_Cash_Tickets_Controller;
use App\Http\Controllers\Api\Collector\Occupant_Monthly_Payment_Controller;
use App\Http\Controllers\Api\Collector\Reports;
use App\Http\Controllers\Api\Settings\CurrentDateCheckerController;
use App\Http\Controllers\Api\Terminal\TerminalReports;
use App\Http\Controllers\PrivateTransactionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/





//Admin Routes
Route::controller(AdminController::class)->group(function () {
    Route::post('admin/login', 'login');
    Route::post('admin/register', 'register');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/area', AreasController::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/livestockcharges', LivestockChargesController::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/section', SectionController::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/slprivate', SLPrivate::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/collector', CollectorController::class);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/occupant', OccupantsController::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/cash_ticket', Cash_TicketsController::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/void_monthly_rental', MonthlyRentalController::class);
});



Route::middleware('auth:sanctum')->controller(ReportsController::class)->group(function () {
    Route::get('admin/GetAreaAndSection', 'GetAreaAndSection');
    Route::get('admin/OverAllDispenseCashTickets', 'OverAllDispenseCashTickets');
    Route::get('admin/OverAllDispenseCashTicketsPerName', 'OverAllDispenseCashTicketsPerName');
    Route::get('admin/OverAllDispenseCashTicketsPerCollector', 'OverAllDispenseCashTicketsPerCollector');
    Route::get('admin/OverAllMonthlyPayment', 'OverAllMonthlyPayment');
    Route::get('admin/OverAllMonthlyPaymentPerArea', 'OverAllMonthlyPaymentPerArea');
    Route::get('admin/OverAllMonthlyPaymentPerCollector', 'OverAllMonthlyPaymentPerCollector');
    Route::get('admin/MontlyRentalReports', 'MontlyRentalReports');
    Route::get('admin/MontlyRentalReportsChecker', 'MontlyRentalReportsChecker');

});



//TERMINAL ROUTES START

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/puv_type', PuvTypes::class);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/cooperative', Cooperatives::class);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/puv', Puv::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/terminal_route', TerminalRoutes::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('admin/despense_ticket', DispenseTicket::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('terminal/despense_ticket', DispenseTicket::class);
});


Route::middleware('auth:sanctum')->controller(TerminalReports::class)->group(function () {
    Route::get('terminal/TerminalTotalTicketsPerDay', 'TerminalTotalTicketsPerDay');
    Route::get('terminal/OverAllDispenseTerminalTickets', 'OverAllDispenseTerminalTickets');

});

//TERMINAL ROUTES END


//END ADMIN ROUTES

//START PRIVATE TRANSACTION ROUTES
Route::middleware('auth:sanctum')->group(function () {
    Route::post('private_transaction/private_ls', [PrivateTransactionController::class, 'store']);
});

Route::get('private_transaction/latest', [PrivateTransactionController::class, 'latest']);

Route::get('private_transaction/compute_latest', [PrivateTransactionController::class, 'computeLatest']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('private_transaction/daily_total', [PrivateTransactionController::class, 'dailyTotal']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get(
        'private_transaction/history',
        [PrivateTransactionController::class, 'history']
    );
});

Route::middleware('auth:sanctum')->get(
    'private_transaction/compute-by-id/{id}',
    [PrivateTransactionController::class, 'computeById']
)->whereNumber('id');

Route::post(
    '/public-slaughter/cashier/create',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'createByCashier']
);

Route::post(
    '/public-slaughter/cashier/update/{id}',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'updateByCashier']
);

Route::post(
    '/public-slaughter/slaughter/create',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'createBySlaughter']
);

Route::post(
    '/public-slaughter/slaughter/update/{id}',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'updateBySlaughter']
);

Route::get(
    '/public-slaughter/unfinished',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'getUnfinished']
);

Route::get(
    '/public-slaughter/get/{id}',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'getById']
);

Route::get(
    '/public-slaughter/lookup/pending',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'lookupPending']
);

/**
 * ============================================================
 * PUBLIC SLAUGHTER — FETCH TRANSACTION BY ID Used by Public Slaughter (App 2)
 * ------------------------------------------------------------
 */
Route::get(
    '/public-slaughter/{id}',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'show']
);

/**
 * ============================================================
 * PUBLIC SLAUGHTER — UPDATE BY SLAUGHTER (APP 2) Updates an existing public transaction by ID
 * ------------------------------------------------------------
 */
Route::patch(
    '/public-slaughter/slaughter/{id}',
    [\App\Http\Controllers\PublicSlaughterTransactionController::class, 'updateBySlaughter']
);

//END OF PRIVATE TRANSACTION ROUTES


// START BOTIKA ON WHEELS ROUTES

    Route::middleware('auth:sanctum')->group(function () {

        Route::resource(
            'bow/barangay',
            \App\Http\Controllers\Api\Bow\BarangayController::class
        );

        Route::resource(
            'bow/purok',
            \App\Http\Controllers\Api\Bow\PurokController::class
        );

        Route::get(
            'bow/purok/by-barangay/{barangay_id}',
            [\App\Http\Controllers\Api\Bow\PurokController::class, 'getByBarangay']
        );

    });


    /**
     * ============================================================================
     * BOTIKA ON WHEELS – PATIENT ROUTES
     * ----------------------------------------------------------------------------
     * - Uses sanctum auth middleware
     * - Resource routes for CRUD
     * - Explicit by-barangay route (consistent with purok pattern)
     * ============================================================================
     */
    Route::middleware('auth:sanctum')->group(function () {

        Route::resource(
            'bow/patient',
            \App\Http\Controllers\Api\Bow\PatientController::class
        );

        Route::get(
            'bow/patient/by-barangay/{barangay_id}',
            [\App\Http\Controllers\Api\Bow\PatientController::class, 'getByBarangay']
        );

    });

    Route::middleware('auth:sanctum')->group(function () {

        Route::resource(
            'bow/physician',
            \App\Http\Controllers\Api\Bow\PhysicianController::class
        );

    });   
    
    Route::middleware('auth:sanctum')->group(function () {

        Route::resource(
            'bow/medicine',
            \App\Http\Controllers\Api\Bow\MedicineController::class
        );

        Route::patch(
            'bow/medicine/{id}/status',
            [\App\Http\Controllers\Api\Bow\MedicineController::class, 'toggleStatus']
        );

    });

    Route::middleware('auth:sanctum')->group(function () {

        Route::get(
            'bow/prescription/by-patient/{patient_id}',
            [\App\Http\Controllers\Api\Bow\PrescriptionController::class, 'getByPatient']
        );

    });
    
    Route::middleware('auth:sanctum')->post(
        'bow/prescription',
        [\App\Http\Controllers\Api\Bow\PrescriptionController::class, 'store']
    );

    Route::middleware('auth:sanctum')->group(function () {

        Route::get(
            'bow/dashboard/community-health',
            [\App\Http\Controllers\Api\Bow\DashboardStatsController::class, 'communityHealthSnapshot']
        );

        Route::get(
            'bow/dashboard/top-counts',
            [\App\Http\Controllers\Api\Bow\DashboardStatsController::class, 'topCardCounts']
        );


    });


    
// END BOTIKA ON WHEELS ROUTES




//Collector Routes
Route::controller(CollectorLoginController::class)->group(function () {
    Route::post('collector/login', 'login');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('collector/cash_ticket', Get_Cash_Tickets_Controller::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('collector/dispense_cash_ticket', Dispense_Cash_Tickets_Controller::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::resource('collector/occupant_monthly_payment', Occupant_Monthly_Payment_Controller::class);
});

Route::middleware('auth:sanctum')->controller(Reports::class)->group(function () {
    Route::get('collector/CollectorTotalCashTicketsPerDay', 'CollectorTotalCashTicketsPerDay');
    Route::get('collector/CashTicketsTransactions', 'CashTicketsTransactions');

});


//END COLLECTOR ROUTES



//Settings Route
Route::controller(CurrentDateCheckerController::class)->group(function () {
    Route::get('settings/GetCurrentDate', 'GetCurrentDate');
});
