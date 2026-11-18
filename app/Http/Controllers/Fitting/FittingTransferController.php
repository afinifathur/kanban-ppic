<?php

namespace App\Http\Controllers\Fitting;

use App\Http\Controllers\Controller;
use App\Services\Fitting\FittingKitirDecisionService;
use App\Services\Fitting\FittingStageAuthorizationService;
use App\Services\Fitting\FittingTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FittingTransferController extends Controller
{
    public function __construct(
        private readonly FittingStageAuthorizationService $authService,
        private readonly FittingTransferService $transferService,
        private readonly FittingKitirDecisionService $decisionService
    ) {}

    /**
     * Display the Fitting Transfer Kitir workspace (Tabs: Available, Transferred / Netto PF, Decision History).
     */
    public function index(Request $request)
    {
        try {
            $this->authService->authorizeTransferKitir(auth()->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $filters = [
            'tab' => $request->query('tab', 'available'),
            'search' => $request->query('search', ''),
            'date_from' => $request->query('date_from', ''),
            'date_to' => $request->query('date_to', ''),
            'line' => $request->query('line', ''),
        ];

        $workspace = $this->transferService->getWorkspaceData($filters);

        return view('fitting.transfers.index', [
            'tab' => $workspace['tab'],
            'availableRows' => $workspace['available_rows'],
            'transferredItems' => $workspace['transferred_items'],
            'decisions' => $workspace['decisions'],
            'counts' => $workspace['counts'],
            'filters' => $filters,
            'lineLabels' => \App\Models\FittingItem::LINE_LABELS,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /**
     * Execute partial or batch transfer of selected issued Lost Wax Kitirs into Fitting Production (NETTO PF).
     */
    public function store(Request $request)
    {
        try {
            $this->authService->authorizeTransferKitir(auth()->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $validated = $request->validate([
            'kitir_ids' => 'required|array|min:1',
            'kitir_ids.*' => 'required|integer|min:1',
            'kitir_lines' => 'required|array',
            'kitir_lines.*' => 'nullable|integer|in:1,2,3,4',
            'notes' => 'nullable|string|max:1000',
            'idempotency_key' => 'nullable|string|max:64',
        ], [
            'kitir_ids.required' => 'Pilih minimal satu Kitir Produksi (PFKTR) untuk ditransfer.',
            'kitir_ids.min' => 'Pilih minimal satu Kitir Produksi (PFKTR) untuk ditransfer.',
            'kitir_lines.required' => 'Pilihan Line Fitting wajib ditentukan untuk seluruh Kitir yang dipilih.',
            'kitir_lines.*.integer' => 'Pilihan Line Fitting harus berupa angka Line 1, 2, 3, atau 4.',
            'kitir_lines.*.in' => 'Pilihan Line Fitting harus bernilai Line 1, 2, 3, atau 4.',
        ]);

        try {
            $transfer = $this->transferService->transferKitirs(
                $validated['kitir_ids'],
                auth()->user(),
                $validated['notes'] ?? null,
                $validated['idempotency_key'] ?? null,
                null,
                $validated['kitir_lines']
            );

            return redirect()
                ->route('fitting.transfers.index', ['tab' => 'transferred'])
                ->with(
                    'success',
                    "Transfer [{$transfer->transfer_number}] berhasil: {$transfer->total_kitirs} Kitir (".number_format($transfer->total_pcs).' PCS / '.number_format((float) $transfer->total_weight_kg, 2, ',', '.').' KG) masuk antrean NETTO PF.'
                );
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('fitting.transfers.index', ['tab' => 'available'])
                ->withInput()
                ->withErrors(['transfer_error' => $e->getMessage()]);
        }
    }

    /**
     * Cancel an active FittingItem transfer (allowed ONLY before any physical stage scan).
     */
    public function cancelItem(Request $request, \App\Models\FittingItem $fittingItem)
    {
        try {
            $this->authService->authorizeTransferKitir(auth()->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $validated = $request->validate([
            'cancel_reason' => 'required|string|min:3|max:500',
        ], [
            'cancel_reason.required' => 'Alasan pembatalan transfer wajib diisi.',
            'cancel_reason.min' => 'Alasan pembatalan transfer minimal 3 karakter.',
        ]);

        try {
            $cancelled = $this->transferService->cancelTransferItem(
                $fittingItem,
                auth()->user(),
                $validated['cancel_reason']
            );

            return redirect()
                ->route('fitting.transfers.index', ['tab' => 'transferred'])
                ->with('success', "Transfer Kitir [{$cancelled->traveler_number}] berhasil dibatalkan dan Kitir kembali tersedia di daftar transfer.");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('fitting.transfers.index', ['tab' => 'transferred'])
                ->withErrors(['transfer_error' => $e->getMessage()]);
        }
    }

    /**
     * Mark an issued, untransferred Kitir as NOT_CONTINUED (reason optional, no hard delete).
     */
    public function markNotContinued(Request $request, \App\Models\LostWaxKitir $kitir)
    {
        try {
            $this->authService->authorizeTransferKitir(auth()->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $decision = $this->decisionService->markNotContinued(
                $kitir,
                auth()->user(),
                $validated['reason'] ?? null
            );

            return redirect()
                ->route('fitting.transfers.index', ['tab' => 'decisions'])
                ->with('success', "Kitir [{$kitir->traveler_number}] ditandai TIDAK DILANJUTKAN (NOT_CONTINUED) dan dicatat pada Decision History.");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('fitting.transfers.index', ['tab' => 'available'])
                ->withErrors(['transfer_error' => $e->getMessage()]);
        }
    }

    /**
     * Undo / Reopen an active NOT_CONTINUED decision so the Kitir returns to the active transfer pool.
     */
    public function reopenDecision(Request $request, \App\Models\FittingKitirDecision $decision)
    {
        try {
            $this->authService->authorizeTransferKitir(auth()->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $validated = $request->validate([
            'reopen_reason' => 'nullable|string|max:1000',
        ]);

        try {
            $reopened = $this->decisionService->reopenDecision(
                $decision,
                auth()->user(),
                $validated['reopen_reason'] ?? null
            );

            $traveler = $reopened->lostWaxKitir?->traveler_number ?? '#'.$reopened->lost_wax_kitir_id;

            return redirect()
                ->route('fitting.transfers.index', ['tab' => 'available'])
                ->with('success', "Kitir [{$traveler}] berhasil dikembalikan ke Daftar Transfer aktif. Histori keputusan sebelumnya tetap tersimpan.");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('fitting.transfers.index', ['tab' => 'decisions'])
                ->withErrors(['transfer_error' => $e->getMessage()]);
        }
    }
}
