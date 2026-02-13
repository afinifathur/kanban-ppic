<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InputController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlanController;

Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::get('/', function () {
        return redirect()->route('dashboard');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/defects', [\App\Http\Controllers\DefectDashboardController::class, 'index'])->name('dashboard.defects');

    // Plan Routes
    Route::get('/plan', [PlanController::class, 'index'])->name('plan.index');
    Route::get('/plan/create', [PlanController::class, 'create'])->name('plan.create');
    Route::post('/plan', [PlanController::class, 'store'])->name('plan.store');

    // Input Routes
    Route::get('/input/{dept}', [InputController::class, 'index'])->name('input.index');
    Route::get('/input/{dept}/create', [InputController::class, 'create'])->name('input.create');
    Route::post('/input/{dept}', [InputController::class, 'store'])->name('input.store');
    Route::get('/input/{dept}/{date}', [InputController::class, 'show'])->name('input.show');
    Route::patch('/input/history/{history}', [InputController::class, 'updateHistory'])->name('input.history.update');
    Route::delete('/input/history/{history}', [InputController::class, 'destroyHistory'])->name('input.history.destroy');

    // Kanban Routes
    Route::get('/kanban/{dept}', [KanbanController::class, 'index'])->name('kanban.index');
    Route::post('/kanban/move', [KanbanController::class, 'move'])->name('kanban.move');
    Route::post('/kanban/reorder', [KanbanController::class, 'reorder'])->name('kanban.reorder');

    // Report Routes
    Route::get('/report', [ReportController::class, 'index'])->name('report.index');
    Route::get('/report/export/{type}', [ReportController::class, 'export'])->name('report.export');

    // Defect Report Routes
    Route::get('/report-defects', [\App\Http\Controllers\DefectReportController::class, 'index'])->name('report-defects.index');
    Route::get('/report-defects/export/{type}', [\App\Http\Controllers\DefectReportController::class, 'export'])->name('report-defects.export');

    // Defect Settings
    Route::get('/settings/defect-types', [\App\Http\Controllers\DefectTypeController::class, 'index'])->name('settings.defect-types.index');
    Route::post('/settings/defect-types', [\App\Http\Controllers\DefectTypeController::class, 'store'])->name('settings.defect-types.store');
    Route::delete('/settings/defect-types/{defectType}', [\App\Http\Controllers\DefectTypeController::class, 'destroy'])->name('settings.defect-types.destroy');

    // Customer Settings
    Route::get('/settings/customers', [\App\Http\Controllers\CustomerController::class, 'index'])->name('settings.customers.index');
    Route::post('/settings/customers', [\App\Http\Controllers\CustomerController::class, 'store'])->name('settings.customers.store');
    Route::delete('/settings/customers/{customer}', [\App\Http\Controllers\CustomerController::class, 'destroy'])->name('settings.customers.destroy');

    // Defect Entry
    Route::get('/defects/{dept}', [\App\Http\Controllers\DefectController::class, 'index'])->name('defects.index');
    Route::post('/defects/{item}', [\App\Http\Controllers\DefectController::class, 'store'])->name('defects.store');
});
