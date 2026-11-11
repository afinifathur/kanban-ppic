<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Services\LostWax\ReadyForOvenService;
use Illuminate\Http\Request;

class ReadyForOvenController extends Controller
{
    public function __construct(
        private readonly ReadyForOvenService $readyForOvenService
    ) {}

    /**
     * Display the preview table for trees ready for Oven.
     */
    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));
        $dailyTargetKg = $request->input('daily_target_kg');
        $poStatus = trim($request->input('po_status', 'all'));
        $codes = (array) $request->input('codes', []);
        $customers = (array) $request->input('customers', []);

        $codes = array_values(array_filter($codes, fn ($c) => $c !== null && $c !== ''));
        $customers = array_values(array_filter($customers, fn ($c) => $c !== null && $c !== ''));

        $scope = null;
        if (auth()->check() && auth()->user()->hasRole('ppic')) {
            $scope = auth()->user()->product_scope;
        }

        $data = $this->readyForOvenService->getQueueData($search, $scope, $dailyTargetKg, $poStatus, $codes, $customers);

        return view('lost-wax.reports.ready-for-oven.index', [
            'items' => $data['items'],
            'summary' => $data['summary'],
            'search' => $search,
            'dailyTargetKg' => $data['summary']['daily_target_kg'],
            'poStatus' => $data['summary']['po_status'],
            'codes' => $codes,
            'customers' => $customers,
            'allCodes' => $data['all_codes'],
            'allCustomers' => $data['all_customers'],
        ]);
    }

    /**
     * Display the printable A4 portrait document for trees ready for Oven.
     */
    public function print(Request $request)
    {
        $search = trim($request->input('search', ''));
        $dailyTargetKg = $request->input('daily_target_kg');
        $poStatus = trim($request->input('po_status', 'all'));
        $codes = (array) $request->input('codes', []);
        $customers = (array) $request->input('customers', []);

        $codes = array_values(array_filter($codes, fn ($c) => $c !== null && $c !== ''));
        $customers = array_values(array_filter($customers, fn ($c) => $c !== null && $c !== ''));

        $scope = null;
        if (auth()->check() && auth()->user()->hasRole('ppic')) {
            $scope = auth()->user()->product_scope;
        }

        $data = $this->readyForOvenService->getQueueData($search, $scope, $dailyTargetKg, $poStatus, $codes, $customers);

        return view('lost-wax.reports.ready-for-oven.print', [
            'items' => $data['items'],
            'summary' => $data['summary'],
            'search' => $search,
            'dailyTargetKg' => $data['summary']['daily_target_kg'],
            'poStatus' => $data['summary']['po_status'],
            'codes' => $codes,
            'customers' => $customers,
            'allCodes' => $data['all_codes'],
            'allCustomers' => $data['all_customers'],
        ]);
    }
}
