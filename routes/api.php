<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Bow\BarangayController;
use App\Http\Controllers\Api\Bow\PrecinctController;
use App\Http\Controllers\Api\Bow\PurokController;
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

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::middleware('permission:bow.manage_geo,bow.view_geo')->group(function () {
        Route::get('bow/barangay', [BarangayController::class, 'index']);
        Route::get('bow/purok/by-barangay/{barangay_id}', [PurokController::class, 'getByBarangay'])
            ->whereNumber('barangay_id');
        Route::get('bow/precinct/by-purok/{purok_id}', [PrecinctController::class, 'getByPurok'])
            ->whereNumber('purok_id');
    });

    Route::middleware('permission:bow.manage_geo')->group(function () {
        Route::post('bow/barangay', [BarangayController::class, 'store']);
        Route::put('bow/barangay/{id}', [BarangayController::class, 'update'])->whereNumber('id');
        Route::patch('bow/barangay/{id}', [BarangayController::class, 'update'])->whereNumber('id');
        Route::delete('bow/barangay/{id}', [BarangayController::class, 'destroy'])->whereNumber('id');

        Route::post('bow/purok', [PurokController::class, 'store']);
        Route::put('bow/purok/{id}', [PurokController::class, 'update'])->whereNumber('id');
        Route::patch('bow/purok/{id}', [PurokController::class, 'update'])->whereNumber('id');
        Route::delete('bow/purok/{id}', [PurokController::class, 'destroy'])->whereNumber('id');

        Route::post('bow/precinct', [PrecinctController::class, 'store']);
        Route::put('bow/precinct/{id}', [PrecinctController::class, 'update'])->whereNumber('id');
        Route::patch('bow/precinct/{id}', [PrecinctController::class, 'update'])->whereNumber('id');
        Route::delete('bow/precinct/{id}', [PrecinctController::class, 'destroy'])->whereNumber('id');
    });
});
