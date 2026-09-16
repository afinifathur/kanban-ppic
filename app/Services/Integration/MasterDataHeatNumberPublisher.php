<?php

namespace App\Services\Integration;

use App\Models\SandCastingCastingResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MasterDataHeatNumberPublisher
{
    protected string $connectionName;

    protected string $tableName;

    public function __construct(?string $connectionName = null, string $tableName = 'md_heat_numbers')
    {
        $this->connectionName = $connectionName ?? 'masterdata_kpi';
        $this->tableName = $tableName;
    }

    /**
     * Publish a single SandCastingCastingResult (Heat) to Master Data KPI.
     *
     * @return array{'success': bool, 'synced_count': int, 'skipped_count': int, 'errors': array, 'payloads': array}
     *
     * @throws Throwable
     */
    public function publishCastingResult(SandCastingCastingResult $result): array
    {
        $payloads = $this->buildPayloadsFromCastingResult($result);

        if (empty($payloads)) {
            Log::info('[SyncHeatNumber:SKIPPED] No valid items to sync for Heat: '.$result->heat_number, [
                'casting_result_id' => $result->id,
                'heat_number' => $result->heat_number,
            ]);

            return [
                'success' => true,
                'synced_count' => 0,
                'skipped_count' => 0,
                'errors' => [],
                'payloads' => [],
            ];
        }

        return $this->publishPayloads($payloads);
    }

    /**
     * Publish by Casting Result ID.
     *
     * @return array{'success': bool, 'synced_count': int, 'skipped_count': int, 'errors': array, 'payloads': array}
     *
     * @throws Throwable
     */
    public function publishCastingResultById(int $resultId): array
    {
        $result = SandCastingCastingResult::with([
            'lines.productionPlan',
            'lines.castingOrderLine.productionPlan',
        ])->find($resultId);

        if (! $result) {
            Log::warning('[SyncHeatNumber:NOT_FOUND] Casting result not found for ID: '.$resultId);

            return [
                'success' => false,
                'synced_count' => 0,
                'skipped_count' => 0,
                'errors' => ["Casting result ID {$resultId} not found"],
                'payloads' => [],
            ];
        }

        return $this->publishCastingResult($result);
    }

    /**
     * Build target payload rows per Casting Result Line (Traveler).
     *
     * Each CastingResultLine represents a physical Traveler (Kitir) with a unique traveler_number.
     * Multiple lines within the same Heat may share the same item_code (e.g. split Kitirs),
     * and each line is published as a distinct physical record.
     */
    public function buildPayloadsFromCastingResult(SandCastingCastingResult $result): array
    {
        if (! $result->relationLoaded('lines')) {
            $result->load([
                'lines.productionPlan',
                'lines.castingOrderLine.productionPlan',
            ]);
        }

        $heatNumber = trim((string) $result->heat_number);
        $castDate = $result->cast_date ? Carbon::parse($result->cast_date)->format('Y-m-d') : null;

        $payloads = [];

        foreach ($result->lines as $line) {
            $plan = $line->productionPlan ?? $line->castingOrderLine?->productionPlan;
            $orderLine = $line->castingOrderLine;

            $itemCode = trim((string) ($plan?->item_code ?? ''));
            if ($itemCode === '') {
                Log::warning('[SyncHeatNumber:MISSING_ITEM_CODE] Line has no item_code, skipping line', [
                    'casting_result_line_id' => $line->id,
                    'heat_number' => $heatNumber,
                ]);

                continue;
            }

            $lineVal = $plan?->line_number;
            $formattedLine = null;
            if ($lineVal !== null && $lineVal !== '') {
                $strLine = (string) $lineVal;
                $formattedLine = str_starts_with(strtoupper($strLine), 'LINE') ? $strLine : 'LINE '.$strLine;
            }

            $travelerNumber = trim((string) ($line->traveler_number ?? ''));

            $payloads[] = [
                'traveler_number' => $travelerNumber !== '' ? $travelerNumber : null,
                'heat_number' => $heatNumber,
                'item_code' => $itemCode,
                'kode_produksi' => $plan?->code ?? $orderLine?->code ?? null,
                'heat_date' => $castDate,
                'item_name' => $plan?->item_name ?? $orderLine?->item_name ?? null,
                'size' => $plan?->size ?? $orderLine?->size ?? null,
                'customer' => $plan?->customer ?? $orderLine?->customer ?? null,
                'line' => $formattedLine,
                'cor_qty' => (int) $line->qty_good,
                'status' => 'active',
            ];
        }

        return $payloads;
    }

    /**
     * Idempotently publish an array of payloads into masterdata_kpi.md_heat_numbers.
     *
     * @return array{'success': bool, 'synced_count': int, 'skipped_count': int, 'errors': array, 'payloads': array}
     *
     * @throws Throwable
     */
    public function publishPayloads(array $payloads): array
    {
        $syncedCount = 0;
        $errors = [];

        foreach ($payloads as $payload) {
            $startTime = microtime(true);
            $heatNumber = $payload['heat_number'] ?? '';
            $itemCode = $payload['item_code'] ?? '';
            $travelerNumber = $payload['traveler_number'] ?? null;

            if ($heatNumber === '' || $itemCode === '') {
                $errors[] = 'Invalid payload: heat_number and item_code are required.';

                continue;
            }

            // Primary match by traveler_number if available; fallback to (heat_number, item_code, traveler_number=null) for historical rows
            if (! empty($travelerNumber)) {
                $match = [
                    'traveler_number' => $travelerNumber,
                ];
            } else {
                $match = [
                    'heat_number' => $heatNumber,
                    'item_code' => $itemCode,
                    'traveler_number' => null,
                ];
            }

            $values = [
                'traveler_number' => $travelerNumber,
                'heat_number' => $heatNumber,
                'item_code' => $itemCode,
                'kode_produksi' => $payload['kode_produksi'] ?? null,
                'heat_date' => $payload['heat_date'] ?? null,
                'item_name' => $payload['item_name'] ?? null,
                'size' => $payload['size'] ?? null,
                'customer' => $payload['customer'] ?? null,
                'line' => $payload['line'] ?? null,
                'cor_qty' => (int) ($payload['cor_qty'] ?? 0),
                'status' => $payload['status'] ?? 'active',
                'updated_at' => now(),
            ];

            try {
                DB::connection($this->connectionName)
                    ->table($this->tableName)
                    ->updateOrInsert($match, $values);

                $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
                $syncedCount++;

                Log::info('[SyncHeatNumber:SUCCESS]', [
                    'traveler_number' => $travelerNumber,
                    'heat_number' => $heatNumber,
                    'item_code' => $itemCode,
                    'cor_qty' => $values['cor_qty'],
                    'elapsed_ms' => $elapsedMs,
                ]);
            } catch (Throwable $e) {
                $isConnectionError = $this->isConnectionException($e);

                if ($isConnectionError) {
                    Log::warning('[SyncHeatNumber:OFFLINE] Master Data KPI connection failed: '.$e->getMessage(), [
                        'traveler_number' => $travelerNumber,
                        'heat_number' => $heatNumber,
                        'item_code' => $itemCode,
                        'connection' => $this->connectionName,
                    ]);
                } else {
                    Log::error('[SyncHeatNumber:FAILED] Failed to sync heat number: '.$e->getMessage(), [
                        'traveler_number' => $travelerNumber,
                        'heat_number' => $heatNumber,
                        'item_code' => $itemCode,
                        'exception' => get_class($e),
                    ]);
                }

                $errors[] = "Failed ({$travelerNumber} / {$heatNumber}, {$itemCode}): ".$e->getMessage();
                throw $e;
            }
        }

        return [
            'success' => count($errors) === 0,
            'synced_count' => $syncedCount,
            'skipped_count' => count($payloads) - $syncedCount - count($errors),
            'errors' => $errors,
            'payloads' => $payloads,
        ];
    }

    /**
     * Check if exception is related to database connection failure.
     */
    protected function isConnectionException(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'connection')
            || str_contains($msg, 'could not connect')
            || str_contains($msg, 'refused')
            || str_contains($msg, 'host')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'access denied');
    }
}
