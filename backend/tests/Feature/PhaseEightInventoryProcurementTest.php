<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseEightInventoryProcurementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_procurement_requires_separate_approval_and_posts_immutable_weighted_average_stock(): void
    {
        [$organization, $branch] = $this->fixture();
        $requester = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $approver = $this->staff('Clinic Administrator', $organization, $branch);
        $accountant = $this->staff('Accountant', $organization, $branch);
        $location = $this->location($requester, $branch);
        $category = $this->actingAs($requester)->postJson('/api/v1/inventory/categories', [
            'name' => 'Skin care',
        ])->assertCreated()->json();
        $supplier = $this->actingAs($requester)->postJson('/api/v1/inventory/suppliers', [
            'name' => 'Clinic Supplies Ltd',
            'currency_code' => 'KES',
        ])->assertCreated()->json();
        $product = $this->actingAs($requester)->postJson('/api/v1/inventory/products', [
            'sku' => 'SERUM-001',
            'name' => 'Hydrating serum',
            'category_id' => $category['id'],
            'unit_of_measure' => 'each',
            'selling_price' => 2500,
            'reorder_level' => 3,
            'tracks_batches' => true,
            'tracks_expiry' => true,
        ])->assertCreated()->json();

        $requisition = $this->actingAs($requester)->postJson('/api/v1/procurement/requisitions', [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier['id'],
            'lines' => [['product_id' => $product['id'], 'quantity' => 10]],
        ])->assertCreated()->json();
        $this->actingAs($requester)->patchJson("/api/v1/procurement/requisitions/{$requisition['id']}/decision", [
            'status' => 'approved',
        ])->assertForbidden();
        $this->actingAs($approver)->patchJson("/api/v1/procurement/requisitions/{$requisition['id']}/decision", [
            'status' => 'approved',
        ])->assertOk()->assertJsonPath('status', 'approved');

        $order = $this->actingAs($requester)->postJson('/api/v1/procurement/purchase-orders', [
            'requisition_id' => $requisition['id'],
            'supplier_id' => $supplier['id'],
            'currency_code' => 'KES',
            'lines' => [['product_id' => $product['id'], 'unit_cost' => 100]],
        ])->assertCreated()->json();
        $this->actingAs($requester)->postJson('/api/v1/procurement/purchase-orders', [
            'requisition_id' => $requisition['id'],
            'supplier_id' => $supplier['id'],
            'currency_code' => 'KES',
            'lines' => [['product_id' => $product['id'], 'unit_cost' => 100]],
        ])->assertConflict();
        $this->actingAs($requester)->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/approve")
            ->assertForbidden();
        $this->actingAs($approver)->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
        $orderLine = DB::table('purchase_order_lines')->where('purchase_order_id', $order['id'])->first();

        $grn = $this->actingAs($requester)->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/receive", [
            'location_id' => $location['id'],
            'lines' => [[
                'purchase_order_line_id' => $orderLine->id,
                'quantity' => 10,
                'lot_code' => 'LOT-A',
                'expires_on' => '2027-11-30',
            ]],
        ])->assertCreated()->json();
        $this->assertDatabaseHas('stock_balances', [
            'location_id' => $location['id'],
            'product_id' => $product['id'],
            'quantity' => '10.000',
            'average_unit_cost' => '100.0000',
        ]);
        $invoice = $this->actingAs($accountant)->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/supplier-invoices", [
            'grn_id' => $grn['id'],
            'invoice_number' => 'INV-100',
            'total_amount' => 1000,
            'invoice_date' => '2026-11-04',
        ])->assertCreated()->json();
        $this->assertSame('recorded', $invoice['status']);
        $this->actingAs($accountant)->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/supplier-invoices", [
            'grn_id' => $grn['id'],
            'invoice_number' => 'INV-WRONG',
            'total_amount' => 999,
            'invoice_date' => '2026-11-04',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'goods_receipt',
            'quantity' => '10.000',
            'total_value' => '1000.00',
        ]);
    }

    public function test_stock_adjustments_transfers_and_stocktakes_reconcile_moving_average_balances(): void
    {
        [$organization, $branch] = $this->fixture();
        $manager = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $destinationBranch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Second branch',
            'code' => 'SECOND',
        ]);
        $manager->branchAssignments()->attach($destinationBranch->id, [
            'organization_id' => $organization->id,
            'effective_from' => now('UTC')->toDateString(),
            'is_primary' => false,
        ]);
        $source = $this->location($manager, $branch, 'Main store', 'MAIN');
        $destination = $this->location($manager, $destinationBranch, 'Second store', 'SECOND');
        $product = $this->actingAs($manager)->postJson('/api/v1/inventory/products', [
            'sku' => 'CREAM-001',
            'name' => 'Daily cream',
            'unit_of_measure' => 'each',
            'selling_price' => 3000,
            'reorder_level' => 2,
        ])->assertCreated()->json();
        $this->actingAs($manager)->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $source['id'],
            'product_id' => $product['id'],
            'quantity_delta' => 10,
            'unit_cost' => 100,
            'reason' => 'Opening stock',
        ])->assertCreated();
        $this->actingAs($manager)->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $source['id'],
            'product_id' => $product['id'],
            'quantity_delta' => 10,
            'unit_cost' => 200,
            'reason' => 'Additional opening stock',
        ])->assertCreated();
        $this->assertDatabaseHas('stock_balances', [
            'location_id' => $source['id'],
            'product_id' => $product['id'],
            'quantity' => '20.000',
            'average_unit_cost' => '150.0000',
        ]);

        $transfer = $this->actingAs($manager)->postJson('/api/v1/inventory/transfers', [
            'from_location_id' => $source['id'],
            'to_location_id' => $destination['id'],
            'lines' => [['product_id' => $product['id'], 'quantity' => 5]],
        ])->assertCreated()->json();
        $this->actingAs($manager)->postJson("/api/v1/inventory/transfers/{$transfer['id']}/dispatch")
            ->assertOk()->assertJsonPath('status', 'dispatched');
        $this->actingAs($manager)->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receive")
            ->assertOk()->assertJsonPath('status', 'received');
        $this->assertDatabaseHas('stock_balances', [
            'location_id' => $source['id'],
            'product_id' => $product['id'],
            'quantity' => '15.000',
            'average_unit_cost' => '150.0000',
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'location_id' => $destination['id'],
            'product_id' => $product['id'],
            'quantity' => '5.000',
            'average_unit_cost' => '150.0000',
        ]);
        $this->actingAs($manager)->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $source['id'],
            'product_id' => $product['id'],
            'quantity_delta' => -16,
            'reason' => 'Attempt to issue more than available',
        ])->assertConflict();

        $stocktake = $this->actingAs($manager)->postJson('/api/v1/inventory/stocktakes', [
            'location_id' => $destination['id'],
            'lines' => [['product_id' => $product['id'], 'counted_quantity' => 4]],
        ])->assertCreated()->json();
        $stocktakeApprover = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $stocktakeApprover->branchAssignments()->attach($destinationBranch->id, [
            'organization_id' => $organization->id,
            'effective_from' => now('UTC')->toDateString(),
            'is_primary' => false,
        ]);
        $this->actingAs($stocktakeApprover)->postJson("/api/v1/inventory/stocktakes/{$stocktake['id']}/post")
            ->assertOk();
        $this->assertDatabaseHas('stock_balances', [
            'location_id' => $destination['id'],
            'product_id' => $product['id'],
            'quantity' => '4.000',
        ]);
        $movementCount = DB::table('stock_movements')->count();
        $this->actingAs($stocktakeApprover)->postJson("/api/v1/inventory/stocktakes/{$stocktake['id']}/post")
            ->assertStatus(409);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
    }

    public function test_inventory_and_procurement_permissions_are_branch_scoped(): void
    {
        [$organization, $branch] = $this->fixture();
        $manager = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $this->actingAs($manager)->getJson('/api/v1/inventory/overview')->assertOk();
        $this->assertFalse($manager->can('procurement.invoice.manage'));

        $otherOrganization = Organization::create([
            'name' => 'Other organization',
            'slug' => 'other-organization',
        ]);
        $otherBranch = Branch::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other branch',
            'code' => 'OTHER',
        ]);
        $otherAdmin = $this->staff('Super Admin', $otherOrganization, $otherBranch);
        $otherLocation = $this->location($otherAdmin, $otherBranch, 'Other store', 'OTHER');
        $this->actingAs($manager)->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $otherLocation['id'],
            'product_id' => 1,
            'quantity_delta' => 1,
            'unit_cost' => 1,
            'reason' => 'Cross-tenant attempt',
        ])->assertNotFound();

        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branch);
        $this->actingAs($receptionist)->getJson('/api/v1/inventory/overview')->assertForbidden();
    }

    /**
     * @return array{Organization, Branch}
     */
    public function test_product_edits_change_details_pricing_and_archive_the_product(): void
    {
        [$organization, $branch] = $this->fixture();
        $manager = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $accountant = $this->staff('Accountant', $organization, $branch);
        $category = $this->actingAs($manager)
            ->postJson('/api/v1/inventory/categories', ['name' => 'Serums'])
            ->assertCreated()
            ->json();

        $product = $this->actingAs($manager)->postJson('/api/v1/inventory/products', [
            'sku' => 'SERUM-OLD',
            'name' => 'Old serum',
            'unit_of_measure' => 'each',
            'selling_price' => 1000,
            'reorder_level' => 2,
        ])->assertCreated()->json();

        $this->actingAs($manager)
            ->patchJson('/api/v1/inventory/products/'.$product['id'], [
                'sku' => 'SERUM-NEW',
                'name' => 'Radiance serum',
                'category_id' => $category['id'],
                'selling_price' => 1800,
                'reorder_level' => 4,
                'is_public' => true,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('sku', 'SERUM-NEW')
            ->assertJsonPath('name', 'Radiance serum')
            ->assertJsonPath('is_active', 0)
            ->assertJsonPath('is_public', 1);

        $this->assertDatabaseHas('inventory_products', [
            'id' => $product['id'],
            'sku' => 'SERUM-NEW',
            'is_active' => false,
        ]);

        $managerOverview = $this->actingAs($manager)->getJson('/api/v1/inventory/overview')->assertOk();
        $this->assertContains($product['id'], collect($managerOverview->json('products.data'))->pluck('id')->all());

        $accountantOverview = $this->actingAs($accountant)->getJson('/api/v1/inventory/overview')->assertOk();
        $this->assertNotContains($product['id'], collect($accountantOverview->json('products.data'))->pluck('id')->all());
    }

    public function test_products_hold_multiple_ordered_images_with_a_primary(): void
    {
        Storage::fake('public');
        [$organization, $branch] = $this->fixture();
        $manager = $this->staff('Sales & Inventory Officer', $organization, $branch);

        $productId = $this->actingAs($manager)->postJson('/api/v1/inventory/products', [
            'sku' => 'IMG-CREAM',
            'name' => 'Image cream',
            'unit_of_measure' => 'each',
            'selling_price' => 1200,
            'reorder_level' => 1,
            'is_public' => true,
        ])->assertCreated()->json()['id'];

        $uploaded = $this->actingAs($manager)->post('/api/v1/inventory/products/'.$productId.'/images', [
            'images' => [
                UploadedFile::fake()->image('front.jpg', 640, 480),
                UploadedFile::fake()->image('back.png', 640, 480),
            ],
        ], ['Accept' => 'application/json'])->assertCreated()->json('images');

        $this->assertCount(2, $uploaded);
        $this->assertTrue($uploaded[0]['is_primary']);
        $this->assertFalse($uploaded[1]['is_primary']);

        $promoted = $this->actingAs($manager)
            ->patchJson('/api/v1/inventory/products/'.$productId.'/images/'.$uploaded[1]['id'].'/primary')
            ->assertOk()
            ->json('images');
        $this->assertTrue($promoted[1]['is_primary']);
        $this->assertFalse($promoted[0]['is_primary']);

        $this->actingAs($manager)
            ->deleteJson('/api/v1/inventory/products/'.$productId.'/images/'.$uploaded[1]['id'])
            ->assertOk()
            ->assertJsonCount(1, 'images');

        $this->assertDatabaseCount('inventory_product_images', 1);
    }

    private function fixture(): array
    {
        $organization = Organization::create([
            'name' => 'Inventory Clinic',
            'slug' => 'inventory-clinic',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'MAIN',
        ]);

        return [$organization, $branch];
    }

    private function staff(string $role, Organization $organization, Branch $branch): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function location(User $user, Branch $branch, string $name = 'Main store', string $code = 'MAIN'): array
    {
        return $this->actingAs($user)->postJson('/api/v1/inventory/locations', [
            'branch_id' => $branch->id,
            'name' => $name,
            'code' => $code,
        ])->assertCreated()->json();
    }
}
