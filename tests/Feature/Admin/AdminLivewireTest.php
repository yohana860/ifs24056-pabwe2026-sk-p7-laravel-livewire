<?php

namespace Tests\Feature\Admin;

use App\Features\Admin\Livewire\AdminUserStockList;
use App\Features\Admin\Livewire\UserList;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_forbidden_from_admin_pages(): void
    {
        $member = User::factory()->member()->create();
        $targetUser = User::factory()->member()->create();

        $this->actingAs($member)->get('/admin/users')->assertStatus(403);
        $this->actingAs($member)->get("/admin/users/{$targetUser->id}/stocks")->assertStatus(403);
    }

    public function test_guest_redirected_from_admin_pages(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));
    }

    public function test_admin_can_view_user_list_with_stock_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $budi = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@test.com']);
        User::factory()->create(['name' => 'Citra Lestari', 'email' => 'citra@test.com']);
        Stock::factory()->count(3)->create(['user_id' => $budi->id]);

        $this->actingAs($admin)->get('/admin/users')->assertOk();

        Livewire::actingAs($admin)
            ->test(UserList::class)
            ->assertSee('Budi Santoso')
            ->assertSee('Citra Lestari')
            ->set('search', 'Budi')
            ->assertSee('Budi Santoso')
            ->assertDontSee('Citra Lestari');
    }

    public function test_admin_can_view_user_stocks_read_only(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create(['name' => 'Dewi Sartika']);

        Stock::factory()->create(['user_id' => $member->id, 'name' => 'Beras Admin View', 'status' => 'available']);
        Stock::factory()->create(['user_id' => $member->id, 'name' => 'Gula Admin View', 'quantity' => 0, 'status' => 'out_of_stock']);
        Stock::factory()->create(['name' => 'Stok User Lain']);

        $this->actingAs($admin)->get("/admin/users/{$member->id}/stocks")->assertOk();

        Livewire::actingAs($admin)
            ->test(AdminUserStockList::class, ['user' => $member])
            ->assertSee('Dewi Sartika')
            ->assertSee('Beras Admin View')
            ->assertSee('Gula Admin View')
            ->assertDontSee('Stok User Lain')
            ->assertDontSee('adjustQuantity')
            ->assertDontSee('confirmDelete')
            ->set('search', 'Beras')
            ->assertSee('Beras Admin View')
            ->assertDontSee('Gula Admin View')
            ->set('search', '')
            ->set('statusFilter', 'out_of_stock')
            ->assertSee('Gula Admin View')
            ->assertDontSee('Beras Admin View');
    }

    public function test_admin_stock_list_component_exposes_no_write_actions(): void
    {
        foreach (['adjustQuantity', 'saveStock', 'deleteStock', 'updateStock', 'createStock', 'importExcel'] as $method) {
            $this->assertFalse(method_exists(AdminUserStockList::class, $method), "{$method} must not exist on the admin component");
        }
    }

    public function test_admin_can_export_user_stocks(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create(['name' => 'Eko Prasetyo']);
        Stock::factory()->create(['user_id' => $member->id, 'name' => 'Export Admin']);

        Livewire::actingAs($admin)
            ->test(AdminUserStockList::class, ['user' => $member])
            ->call('exportExcel')
            ->assertFileDownloaded();
    }
}
