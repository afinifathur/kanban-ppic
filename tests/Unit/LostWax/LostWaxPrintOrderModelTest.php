<?php

namespace Tests\Unit\LostWax;

use App\Models\LostWaxPrintOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LostWaxPrintOrderModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * CASE A: Create REGULAR order and test defaults.
     */
    public function test_case_a_create_regular_order_defaults(): void
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0001',
            'scheduled_date' => '2026-08-28',
            'created_by' => $this->user->id,
        ]);

        $this->assertSame('REGULAR', $order->order_type);
        $this->assertSame(0, $order->reprint_cycle);
        $this->assertNull($order->reprint_reason);
        $this->assertTrue($order->isRegular());
        $this->assertFalse($order->isReprint());
    }

    /**
     * CASE B: Create REPRINT order and verify attributes.
     */
    public function test_case_b_create_reprint_order(): void
    {
        $order = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0002',
            'scheduled_date' => '2026-08-28',
            'order_type' => 'REPRINT',
            'reprint_reason' => 'Kompensasi 40 pcs retak di Layer 3',
            'reprint_cycle' => 1,
            'created_by' => $this->user->id,
        ]);

        $this->assertSame('REPRINT', $order->order_type);
        $this->assertSame(1, $order->reprint_cycle);
        $this->assertSame('Kompensasi 40 pcs retak di Layer 3', $order->reprint_reason);
        $this->assertFalse($order->isRegular());
        $this->assertTrue($order->isReprint());
    }

    /**
     * CASE C: Existing order compatibility and default fallback.
     */
    public function test_case_c_existing_order_compatibility(): void
    {
        $order = new LostWaxPrintOrder;
        $order->print_order_number = 'PC-20260820-0001';
        $order->scheduled_date = '2026-08-20';
        $order->created_by = $this->user->id;
        $order->save();

        $fresh = LostWaxPrintOrder::find($order->id);
        $this->assertSame('REGULAR', $fresh->order_type);
        $this->assertSame(0, $fresh->reprint_cycle);
        $this->assertNull($fresh->reprint_reason);
        $this->assertTrue($fresh->isRegular());
    }

    /**
     * CASE D: Reprint cycle integer casting across multi-cycles.
     */
    public function test_case_d_reprint_cycle_casting(): void
    {
        $order1 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0003',
            'scheduled_date' => '2026-08-28',
            'order_type' => 'REPRINT',
            'reprint_cycle' => '1',
            'created_by' => $this->user->id,
        ]);

        $order2 = LostWaxPrintOrder::create([
            'print_order_number' => 'PC-20260828-0004',
            'scheduled_date' => '2026-08-28',
            'order_type' => 'REPRINT',
            'reprint_cycle' => 2,
            'created_by' => $this->user->id,
        ]);

        $this->assertIsInt($order1->reprint_cycle);
        $this->assertSame(1, $order1->reprint_cycle);
        $this->assertIsInt($order2->reprint_cycle);
        $this->assertSame(2, $order2->reprint_cycle);
    }
}
