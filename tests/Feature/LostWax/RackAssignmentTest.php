<?php

namespace Tests\Feature\LostWax;

use App\Models\LostWaxCoatingRack;
use App\Models\LostWaxTree;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RackAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_rack_assignment()
    {
        $user = User::factory()->create();
        $rack = LostWaxCoatingRack::create(['rack_number' => 17, 'status' => 'active']);
        $tree = LostWaxTree::create([
            'barcode' => '260821001',
            'tree_number' => 1,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 1,
        ]);

        $response = $this->actingAs($user)->patchJson(route('lost-wax.trees.update-rack', $tree), [
            'rack_id' => $rack->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Tree berhasil ditempatkan ke RAK-17.',
            'rack_label' => 'RAK-17',
            'is_over_capacity' => false,
            'count' => 1,
        ]);

        $this->assertEquals($rack->id, $tree->fresh()->rack_id);
    }

    public function test_single_rack_assignment_clear()
    {
        $user = User::factory()->create();
        $rack = LostWaxCoatingRack::create(['rack_number' => 17, 'status' => 'active']);
        $tree = LostWaxTree::create([
            'barcode' => '260821001',
            'tree_number' => 1,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 1,
            'rack_id' => $rack->id,
            'rack_assigned_at' => now(),
        ]);

        $response = $this->actingAs($user)->patchJson(route('lost-wax.trees.update-rack', $tree), [
            'rack_id' => null,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Tree berhasil dikeluarkan dari rak.',
            'rack_label' => '-',
            'is_over_capacity' => false,
            'count' => 0,
        ]);

        $this->assertNull($tree->fresh()->rack_id);
    }

    public function test_bulk_rack_assignment()
    {
        $user = User::factory()->create();
        $rack = LostWaxCoatingRack::create(['rack_number' => 1, 'status' => 'active']);
        $tree1 = LostWaxTree::create([
            'barcode' => '260821001',
            'tree_number' => 1,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 1,
        ]);
        $tree2 = LostWaxTree::create([
            'barcode' => '260821002',
            'tree_number' => 2,
            'quantity' => 15,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 2,
        ]);

        $response = $this->actingAs($user)->postJson(route('lost-wax.trees.bulk-rack'), [
            'tree_ids' => [$tree1->id, $tree2->id],
            'rack_id' => $rack->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => '2 tree berhasil ditempatkan ke RAK-01.',
            'success_count' => 2,
            'fail_count' => 0,
        ]);

        $this->assertEquals($rack->id, $tree1->fresh()->rack_id);
        $this->assertEquals($rack->id, $tree2->fresh()->rack_id);
    }

    public function test_over_capacity_warning()
    {
        $user = User::factory()->create();
        $rack = LostWaxCoatingRack::create(['rack_number' => 17, 'status' => 'active']);

        // Create 30 trees already on the rack
        for ($i = 0; $i < 30; $i++) {
            LostWaxTree::create([
                'barcode' => 'BC-'.$i,
                'tree_number' => $i + 1,
                'quantity' => 10,
                'status' => 'generated',
                'production_date' => now(),
                'family_code' => '3',
                'daily_sequence' => $i + 1,
                'rack_id' => $rack->id,
                'rack_assigned_at' => now(),
            ]);
        }

        // Assign the 31st tree
        $tree = LostWaxTree::create([
            'barcode' => 'BC-31',
            'tree_number' => 31,
            'quantity' => 10,
            'status' => 'generated',
            'production_date' => now(),
            'family_code' => '3',
            'daily_sequence' => 31,
        ]);

        $response = $this->actingAs($user)->patchJson(route('lost-wax.trees.update-rack', $tree), [
            'rack_id' => $rack->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_over_capacity' => true,
            'count' => 31,
        ]);
    }
}
