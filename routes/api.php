<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\AccountManagementController;
use App\Http\Controllers\Api\Bow\BarangayController;
use App\Http\Controllers\Api\Bow\DashboardStatsController;
use App\Http\Controllers\Api\Bow\PrecinctController;
use App\Http\Controllers\Api\Bow\PurokController;
use App\Http\Controllers\Api\Bow\RecipientController;
use App\Http\Controllers\Api\Bow\ReligionController;
use App\Http\Controllers\Api\Bow\TribeController;
use App\Http\Controllers\Api\Bow\VoterImportController;
use App\Http\Controllers\Api\Bow\VoterReportController;
use Illuminate\Support\Facades\Route;

Route::controller(AdminController::class)->group(function () {
    Route::post('admin/login', 'login');
});

Route::middleware(['auth:sanctum'])->controller(AdminController::class)->group(function () {
    Route::post('admin/logout', 'logout');
});

Route::middleware(['auth:sanctum', 'active'])->controller(AdminController::class)->group(function () {
    Route::patch('admin/account/change-password', 'changePassword');
});

Route::middleware(['auth:sanctum', 'active', 'role:administrator'])->group(function () {
    Route::get('admin/accounts/options', [AccountManagementController::class, 'options']);
    Route::get('admin/accounts', [AccountManagementController::class, 'index']);
    Route::get('admin/accounts/{id}', [AccountManagementController::class, 'show'])->whereNumber('id');
    Route::post('admin/accounts', [AccountManagementController::class, 'store']);
    Route::put('admin/accounts/{id}', [AccountManagementController::class, 'update'])->whereNumber('id');
    Route::patch('admin/accounts/{id}', [AccountManagementController::class, 'update'])->whereNumber('id');
    Route::delete('admin/accounts/{id}', [AccountManagementController::class, 'destroy'])->whereNumber('id');
    Route::get('bow/reports/voters/records', [VoterReportController::class, 'records']);
    Route::get('bow/reports/voters', [VoterReportController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::middleware('permission:bow.manage_geo,bow.edit_geo,bow.view_geo')->group(function () {
        Route::get('bow/dashboard/voter-insights', [DashboardStatsController::class, 'voterInsights']);
        Route::get('bow/barangay', [BarangayController::class, 'index']);
        Route::get('bow/purok/by-barangay/{barangay_id}', [PurokController::class, 'getByBarangay'])
            ->whereNumber('barangay_id');
        Route::get('bow/precinct/by-purok/{purok_id}', [PrecinctController::class, 'getByPurok'])
            ->whereNumber('purok_id');
        Route::get('bow/religions', [ReligionController::class, 'index']);
        Route::get('bow/tribes', [TribeController::class, 'index']);
        Route::get('bow/recipients', [RecipientController::class, 'index']);
        Route::get('bow/voters', [RecipientController::class, 'index']);
    });

    Route::middleware('permission:bow.manage_geo')->group(function () {
        Route::get('bow/voter-imports', [VoterImportController::class, 'index']);
        Route::post('bow/voter-imports/preview', [VoterImportController::class, 'preview']);
        Route::get('bow/voter-imports/commit-progress/{token}', [VoterImportController::class, 'progress']);
        Route::get('bow/voter-imports/{id}', [VoterImportController::class, 'show'])->whereNumber('id');
        Route::get('bow/voter-imports/{id}/rows', [VoterImportController::class, 'rows'])->whereNumber('id');
        Route::post('bow/voter-imports/{id}/auto-resolve', [VoterImportController::class, 'autoResolve'])->whereNumber('id');
        Route::put('bow/voter-imports/{id}/mappings', [VoterImportController::class, 'mappings'])->whereNumber('id');
        Route::post('bow/voter-imports/{id}/commit', [VoterImportController::class, 'commit'])->whereNumber('id');
        Route::delete('bow/voter-imports/{id}', [VoterImportController::class, 'destroy'])->whereNumber('id');

        Route::post('bow/barangay', [BarangayController::class, 'store']);

        Route::post('bow/purok', [PurokController::class, 'store']);

        Route::post('bow/precinct', [PrecinctController::class, 'store']);

        Route::post('bow/tribes', [TribeController::class, 'store']);

        Route::post('bow/religions', [ReligionController::class, 'store']);

        Route::post('bow/recipients', [RecipientController::class, 'store']);

        Route::post('bow/voters', [RecipientController::class, 'store']);
    });

    Route::middleware('permission:bow.manage_geo,bow.edit_geo')->group(function () {
        Route::put('bow/barangay/{id}', [BarangayController::class, 'update'])->whereNumber('id');
        Route::patch('bow/barangay/{id}', [BarangayController::class, 'update'])->whereNumber('id');

        Route::put('bow/purok/{id}', [PurokController::class, 'update'])->whereNumber('id');
        Route::patch('bow/purok/{id}', [PurokController::class, 'update'])->whereNumber('id');

        Route::put('bow/precinct/{id}', [PrecinctController::class, 'update'])->whereNumber('id');
        Route::patch('bow/precinct/{id}', [PrecinctController::class, 'update'])->whereNumber('id');

        Route::put('bow/tribes/{id}', [TribeController::class, 'update'])->whereNumber('id');
        Route::patch('bow/tribes/{id}', [TribeController::class, 'update'])->whereNumber('id');

        Route::put('bow/religions/{id}', [ReligionController::class, 'update'])->whereNumber('id');
        Route::patch('bow/religions/{id}', [ReligionController::class, 'update'])->whereNumber('id');

        Route::put('bow/recipients/{id}', [RecipientController::class, 'update'])->whereNumber('id');
        Route::patch('bow/recipients/{id}', [RecipientController::class, 'update'])->whereNumber('id');

        Route::put('bow/voters/{id}', [RecipientController::class, 'update'])->whereNumber('id');
        Route::patch('bow/voters/{id}', [RecipientController::class, 'update'])->whereNumber('id');
    });

    Route::middleware(['permission:bow.manage_geo', 'can_delete'])->group(function () {
        Route::delete('bow/voter-imports/{id}/barangay', [VoterImportController::class, 'destroyBarangay'])
            ->whereNumber('id');
        Route::delete('bow/barangay/{id}', [BarangayController::class, 'destroy'])->whereNumber('id');
        Route::delete('bow/purok/{id}', [PurokController::class, 'destroy'])->whereNumber('id');
        Route::delete('bow/precinct/{id}', [PrecinctController::class, 'destroy'])->whereNumber('id');
        Route::delete('bow/religions/{id}', [ReligionController::class, 'destroy'])->whereNumber('id');
        Route::delete('bow/tribes/{id}', [TribeController::class, 'destroy'])->whereNumber('id');
        Route::delete('bow/recipients/{id}', [RecipientController::class, 'destroy'])->whereNumber('id');
        Route::delete('bow/voters/{id}', [RecipientController::class, 'destroy'])->whereNumber('id');
    });
});
