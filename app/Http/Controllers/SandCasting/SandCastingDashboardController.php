<?php

namespace App\Http\Controllers\SandCasting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SandCasting\SandCastingDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SandCastingDashboardController extends Controller
{
    public function __construct(
        protected SandCastingDashboardService $dashboardService
    ) {}

    /**
     * Check if the authenticated user is authorized to view Sand Casting dashboard data.
     * Allowed: admin, ppic, qc, admin_qc, spv, or users with access_planning / access_execution permissions.
     */
    public static function isUserAuthorized(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole('admin') || $user->hasRole('ppic') || $user->hasRole('spv')) {
            return true;
        }

        if ($user->hasRole('qc') || $user->hasRole('admin_qc')) {
            return true;
        }

        try {
            if ($user->hasPermissionTo('access_planning') || $user->hasPermissionTo('access_execution')) {
                return true;
            }
        } catch (\Throwable $e) {
            // User has no permissions
        }

        return false;
    }

    /**
     * Ensure the user has permission to access dashboard data.
     */
    protected function authorizeDashboardAccess(Request $request): void
    {
        if (! self::isUserAuthorized($request->user())) {
            abort(403, 'Anda tidak memiliki wewenang untuk mengakses dashboard Sand Casting.');
        }
    }

    /**
     * Entry point for the Sand Casting dashboard web page.
     * Renders multi-zone industrial dashboard view with preloaded initial payload.
     *
     * @return \Illuminate\View\View|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorizeDashboardAccess($request);

        $zone = (string) $request->query('zone', 'all');
        if (! in_array($zone, ['all', '1', '2', '3'], true)) {
            $zone = 'all';
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'active_zone' => $zone,
                'data_endpoint' => route('sand-casting.dashboard.data', ['zone' => $zone]),
            ]);
        }

        $initialData = $this->dashboardService->getData($zone, $request->user());

        return view('sand-casting.dashboard.index', [
            'activeZone' => $zone,
            'initialData' => $initialData,
        ]);
    }

    /**
     * Fetch aggregated JSON dashboard data for specified zone (all, 1, 2, 3).
     *
     * @throws ValidationException
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorizeDashboardAccess($request);

        $validator = Validator::make($request->all(), [
            'zone' => 'nullable|string|in:all,1,2,3',
            'fresh' => 'nullable|boolean',
        ], [
            'zone.in' => 'Parameter zone tidak valid. Pilihan: all, 1, 2, 3.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $zone = (string) $request->query('zone', 'all');
        $bypassCache = $request->boolean('fresh', false);

        $payload = $this->dashboardService->getData($zone, $request->user(), $bypassCache);

        return response()->json($payload);
    }
}
