<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PrintJobApiController extends Controller
{
    private readonly \App\Services\Barcode\PrintJobService $printJobService;

    public function __construct()
    {
        $this->printJobService = new \App\Services\Barcode\PrintJobService;
    }

    public function claim(Request $request)
    {
        $request->validate([
            'machine_id' => 'required|string',
            'printer_name' => 'required|string',
        ]);

        $job = $this->printJobService->claimNextJob(
            $request->machine_id,
            $request->printer_name
        );

        if (! $job) {
            return response()->noContent();
        }

        return response()->json($job);
    }

    public function complete(int $id)
    {
        $this->printJobService->markPrinted($id);

        return response()->json(['message' => 'Job marked as completed']);
    }

    public function failed(Request $request, int $id)
    {
        $request->validate(['error' => 'required|string']);
        $this->printJobService->markFailed($id, $request->error);

        return response()->json(['message' => 'Job marked as failed']);
    }

    public function recover()
    {
        $count = $this->printJobService->recoverStaleJobs();

        return response()->json([
            'message' => 'Recovery completed',
            'recovered_count' => $count,
        ]);
    }
}
