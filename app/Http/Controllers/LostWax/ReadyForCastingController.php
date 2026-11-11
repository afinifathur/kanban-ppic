<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Services\LostWax\ReadyForCastingService;
use Illuminate\Http\Request;

class ReadyForCastingController extends Controller
{
    public function __construct(
        private readonly ReadyForCastingService $readyForCastingService
    ) {}

    /**
     * Display the preview table for Traveler Cards ready for casting.
     */
    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));
        $codes = (array) $request->input('codes', []);
        $customers = (array) $request->input('customers', []);

        $codes = array_values(array_filter($codes, fn ($c) => $c !== null && $c !== ''));
        $customers = array_values(array_filter($customers, fn ($c) => $c !== null && $c !== ''));

        $scope = null;
        if (auth()->check() && auth()->user()->hasRole('ppic')) {
            $scope = auth()->user()->product_scope;
        }

        $data = $this->readyForCastingService->getQueueData($search, $scope, $codes, $customers);

        return view('lost-wax.reports.ready-for-casting.index', [
            'items' => $data['items'],
            'summary' => $data['summary'],
            'search' => $search,
            'codes' => $codes,
            'customers' => $customers,
            'allCodes' => $data['all_codes'],
            'allCustomers' => $data['all_customers'],
        ]);
    }

    /**
     * Display the printable A4 portrait document for casting control.
     */
    public function print(Request $request)
    {
        $search = trim($request->input('search', ''));
        $codes = (array) $request->input('codes', []);
        $customers = (array) $request->input('customers', []);

        $codes = array_values(array_filter($codes, fn ($c) => $c !== null && $c !== ''));
        $customers = array_values(array_filter($customers, fn ($c) => $c !== null && $c !== ''));

        $scope = null;
        if (auth()->check() && auth()->user()->hasRole('ppic')) {
            $scope = auth()->user()->product_scope;
        }

        $data = $this->readyForCastingService->getQueueData($search, $scope, $codes, $customers);

        return view('lost-wax.reports.ready-for-casting.print', [
            'items' => $data['items'],
            'summary' => $data['summary'],
            'search' => $search,
            'codes' => $codes,
            'customers' => $customers,
            'allCodes' => $data['all_codes'],
            'allCustomers' => $data['all_customers'],
        ]);
    }
}
