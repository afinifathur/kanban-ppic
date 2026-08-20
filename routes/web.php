<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InputController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\LostWax\DashboardController as LostWaxDashboardController;
use App\Http\Controllers\LostWax\ProductionStatusController;
use App\Http\Controllers\LostWax\ScanController;
use App\Http\Controllers\LostWax\TreeController;
use App\Http\Controllers\LostWax\WorkOrderController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WipController;
use Illuminate\Support\Facades\Route;

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
    Route::middleware(['permission:access_planning'])->group(function () {
        Route::get('/plan', [PlanController::class, 'index'])->name('plan.index');
        Route::get('/plan/create', [PlanController::class, 'create'])->name('plan.create');
        Route::post('/plan', [PlanController::class, 'store'])->name('plan.store');
        Route::post('/plan/update-title', [PlanController::class, 'updateTitle'])->name('plan.updateTitle');
        Route::get('/plan/{plan}/edit', [PlanController::class, 'edit'])->name('plan.edit');
        Route::put('/plan/{plan}', [PlanController::class, 'update'])->name('plan.update');
        Route::delete('/plan/{plan}', [PlanController::class, 'destroy'])->name('plan.destroy');
    });

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
    Route::get('/report-defects/summary', [\App\Http\Controllers\DefectReportController::class, 'summary'])->name('report-defects.summary');
    Route::get('/report-defects/export/{type}', [\App\Http\Controllers\DefectReportController::class, 'export'])->name('report-defects.export');

    // Defect Settings
    Route::get('/settings/defect-types', [\App\Http\Controllers\DefectTypeController::class, 'index'])->name('settings.defect-types.index');
    Route::post('/settings/defect-types', [\App\Http\Controllers\DefectTypeController::class, 'store'])->name('settings.defect-types.store');
    Route::put('/settings/defect-types/{defectType}', [\App\Http\Controllers\DefectTypeController::class, 'update'])->name('settings.defect-types.update');

    // Customer Settings
    Route::get('/settings/customers', [\App\Http\Controllers\CustomerController::class, 'index'])->name('settings.customers.index');
    Route::post('/settings/customers', [\App\Http\Controllers\CustomerController::class, 'store'])->name('settings.customers.store');
    Route::delete('/settings/customers/{customer}', [\App\Http\Controllers\CustomerController::class, 'destroy'])->name('settings.customers.destroy');

    // Defect Entry
    Route::get('/defects/{dept}', [\App\Http\Controllers\DefectController::class, 'index'])->name('defects.index');
    Route::post('/defects/{item}', [\App\Http\Controllers\DefectController::class, 'store'])->name('defects.store');

    // WIP & Heat Number Management
    Route::get('/wip', [WipController::class, 'index'])->name('wip.index');
    Route::get('/wip/{date}', [WipController::class, 'show'])->name('wip.show');
    Route::post('/wip/update', [WipController::class, 'update'])->name('wip.update');
    Route::get('/report/wip', [WipController::class, 'report'])->name('wip.report');

    // Lost Wax Routes
    Route::prefix('lost-wax')->name('lost-wax.')->group(function () {
        Route::get('/', function () {
            if (auth()->user()->can('access_planning')) {
                return redirect()->route('lost-wax.print-orders.plans');
            }

            return redirect()->route('lost-wax.dashboard');
        });

        // Group with permission:access_planning
        Route::middleware(['permission:access_planning'])->group(function () {
            // Print Orders (Perencanaan Perintah Cetak)
            Route::get('/print-orders/plans', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'plans'])->name('print-orders.plans');
            Route::get('/print-orders', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'index'])->name('print-orders.index');
            Route::get('/print-orders/create', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'create'])->name('print-orders.create');
            Route::post('/print-orders', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'store'])->name('print-orders.store');
            Route::get('/print-orders/{printOrder}', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'show'])->name('print-orders.show');
            Route::get('/print-orders/{printOrder}/edit', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'edit'])->name('print-orders.edit');
            Route::put('/print-orders/{printOrder}', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'update'])->name('print-orders.update');
            Route::post('/print-orders/{printOrder}/status', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'updateStatus'])->name('print-orders.update-status');
            Route::get('/print-orders/{printOrder}/print', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'print'])->name('print-orders.print');
            Route::delete('/print-orders/{printOrder}', [\App\Http\Controllers\LostWax\PrintOrderController::class, 'destroy'])->name('print-orders.destroy');

            // Actual Hasil Cetak
            Route::get('/outcomes', [\App\Http\Controllers\LostWax\OutcomeController::class, 'index'])->name('outcomes.index');
            Route::get('/outcomes/{printOrder}/edit', [\App\Http\Controllers\LostWax\OutcomeController::class, 'editOutcome'])->name('outcomes.edit');
            Route::put('/outcomes/{printOrder}', [\App\Http\Controllers\LostWax\OutcomeController::class, 'updateOutcome'])->name('outcomes.update');

            // Perintah Rangkai (Assembly)
            Route::get('/assemblies', [\App\Http\Controllers\LostWax\AssemblyController::class, 'index'])->name('assemblies.index');
            Route::get('/assemblies/{line}/create', [\App\Http\Controllers\LostWax\AssemblyController::class, 'create'])->name('assemblies.create');
            Route::post('/assemblies/{line}', [\App\Http\Controllers\LostWax\AssemblyController::class, 'store'])->name('assemblies.store');

            // Work Orders
            Route::get('/work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
            Route::get('/work-orders/create', [WorkOrderController::class, 'create'])->name('work-orders.create');
            Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
            Route::get('/work-orders/bulk/create', [WorkOrderController::class, 'bulkCreate'])->name('work-orders.bulk.create');
            Route::post('/work-orders/bulk', [WorkOrderController::class, 'bulkStore'])->name('work-orders.bulk.store');
            Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');
            Route::get('/work-orders/{workOrder}/edit', [WorkOrderController::class, 'edit'])->name('work-orders.edit');
            Route::put('/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('work-orders.update');
            Route::post('/work-orders/{workOrder}/plans', [WorkOrderController::class, 'storePlan'])->name('work-orders.plans.store');
            Route::post('/work-orders/{workOrder}/wip', [WorkOrderController::class, 'storeWip'])->name('work-orders.wip.store');
        });

        // Group with permission:access_execution
        Route::middleware(['permission:access_execution'])->group(function () {
            // Trees
            Route::get('/trees', [TreeController::class, 'index'])->name('trees.index');
            Route::get('/trees/generate/{plan}', [TreeController::class, 'generate'])->name('trees.generate');
            Route::post('/trees/generate/{plan}', [TreeController::class, 'store'])->name('trees.store');
            Route::get('/trees/{tree}/history', [ScanController::class, 'history'])->name('trees.history');
            Route::get('/trees/{tree}', [TreeController::class, 'show'])->name('trees.show');
            Route::patch('/trees/{tree}', [TreeController::class, 'update'])->name('trees.update');
            Route::get('/trees/{tree}/traveler', [TreeController::class, 'traveler'])->name('trees.traveler');
            Route::get('/trees/{tree}/barcode', [TreeController::class, 'barcode'])->name('trees.barcode');

            // Scan
            Route::get('/scan', [ScanController::class, 'index'])->name('scan.index');
            Route::post('/scan', [ScanController::class, 'process'])->name('scan.process');
            Route::post('/stage-label', [ScanController::class, 'stageLabel'])->name('stage-label');

            // Scan Oven
            Route::get('/scan-oven', [ScanController::class, 'scanOven'])->name('scan-oven.index');
            Route::post('/scan-oven', [ScanController::class, 'processOven'])->name('scan-oven.process');

            // Dashboard
            Route::get('/dashboard', [LostWaxDashboardController::class, 'index'])->name('dashboard');

            // Production Status
            Route::get('/production-status', [ProductionStatusController::class, 'index'])->name('production-status');
            Route::get('/production-status/trees', [ProductionStatusController::class, 'trees'])->name('production-status.trees');
            Route::get('/production-status/export', [ProductionStatusController::class, 'exportCsv'])->name('production-status.export');
        });
    });
});

Route::get('/debug-session', function () {
    return [
        'id' => session()->getId(),
        'all' => session()->all(),
        'auth' => auth()->check(),
        'user' => auth()->user(),
    ];
});
