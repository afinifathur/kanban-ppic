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
        $scope = null;

        if (auth()->check() && auth()->user()->hasRole('ppic')) {
            $scope = auth()->user()->product_scope;
        }

        $data = $this->readyForOvenService->getQueueData($search, $scope);

        return view('lost-wax.reports.ready-for-oven.index', [
            'items' => $data['items'],
            'summary' => $data['summary'],
            'search' => $search,
        ]);
    }

    /**
     * Display the printable A4 portrait document for trees ready for Oven.
     */
    public function print(Request $request)
    {
        $search = trim($request->input('search', ''));
        $scope = null;

        if (auth()->check() && auth()->user()->hasRole('ppic')) {
            $scope = auth()->user()->product_scope;
        }

        $data = $this->readyForOvenService->getQueueData($search, $scope);

        return view('lost-wax.reports.ready-for-oven.print', [
            'items' => $data['items'],
            'summary' => $data['summary'],
            'search' => $search,
        ]);
    }
}
