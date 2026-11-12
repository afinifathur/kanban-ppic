<?php

namespace App\Http\Controllers\LostWax;

use App\Http\Controllers\Controller;
use App\Services\LostWax\DewaxingMonitoringService;
use Illuminate\Http\Request;

class DewaxingMonitoringController extends Controller
{
    public function __construct(
        private readonly DewaxingMonitoringService $dewaxingMonitoringService
    ) {}

    /**
     * Display the Dewaxing Process Monitoring page.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 30);
        if ($perPage <= 0 || $perPage > 100) {
            $perPage = 30;
        }

        $page = $request->has('page') ? (int) $request->input('page') : null;

        $data = $this->dewaxingMonitoringService->getMonitoringData($perPage, $page);

        return view('lost-wax.dewaxing-monitoring.index', [
            'waitingItems' => $data['waiting_items'],
            'completedItems' => $data['completed_items'],
            'summary' => $data['summary'],
            'updatedAt' => $data['updated_at'],
        ]);
    }
}
