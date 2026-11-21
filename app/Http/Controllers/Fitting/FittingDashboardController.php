<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fitting\FittingDashboardService;
use App\Services\Fitting\FittingStageAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FittingDashboardController extends Controller
{
    public function __construct(
        protected readonly FittingDashboardService $dashboardService,
        protected readonly FittingStageAuthorizationService $authService
    ) {}

    /**
     * Check if the authenticated user is authorized to view Fitting dashboard data.
     */
    public static function isUserAuthorized(?User $user): bool
    {
        return FittingStageAuthorizationService::canViewDashboard($user);
    }

    /**
     * Ensure the user has permission to access dashboard data.
     *
     * @throws AuthorizationException
     */
    protected function authorizeDashboardAccess(Request $request): void
    {
        $this->authService->authorizeViewDashboard($request->user());
    }

    /**
     * Entry point for the Fitting Dashboard web page.
     * Renders Zone 1 (Aktivitas Produksi Hari Ini) with preloaded initial payload.
     *
     * @return View|JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $this->authorizeDashboardAccess($request);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $zone = (string) $request->query('zone', 'all');
        if (! in_array($zone, ['all', '1', '2', '3', '4'], true)) {
            $zone = 'all';
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'active_zone' => $zone,
                'data_endpoint' => route('fitting.dashboard.data', ['zone' => $zone]),
            ]);
        }

        $initialData = $this->dashboardService->getData($zone, $request->user(), false);

        return view('fitting.dashboard.index', [
            'activeZone' => $zone,
            'initialData' => $initialData,
        ]);
    }

    /**
     * Fetch aggregated JSON dashboard data for Zone 1.
     */
    public function data(Request $request): JsonResponse
    {
        try {
            $this->authorizeDashboardAccess($request);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $zone = (string) $request->query('zone', 'all');
        if (! in_array($zone, ['all', '1', '2', '3', '4'], true)) {
            $zone = 'all';
        }
        $fresh = $request->boolean('fresh', false);
        $data = $this->dashboardService->getData($zone, $request->user(), $fresh);

        return response()->json($data);
    }
}
