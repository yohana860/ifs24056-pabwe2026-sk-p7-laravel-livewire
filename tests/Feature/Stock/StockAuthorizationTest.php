<?php

namespace Tests\Feature\Stock;

use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end ownership / IDOR scenarios from the Praktikum 7 security requirements.
 */
class StockAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_a_stock_is_protected_from_user_b_and_admin_writes(): void
    {
        $userA = User::factory()->member()->create();
        $userB = User::factory()->member()->create();
        $admin = User::factory()->admin()->create();
        $stockA = Stock::factory()->create(['user_id' => $userA->id, 'name' => 'Stock A', 'quantity' => 10]);

        // Owner can open it
        $this->actingAs($userA)->get(route('stocks.show', $stockA))->assertOk();

        // User B: open -> 403 (IDOR)
        $this->actingAs($userB)->get(route('stocks.show', $stockA))->assertForbidden();

        // There are no HTTP write routes for stocks at all: every mutation goes through Livewire + Gate
        $this->actingAs($userB)->put(route('stocks.show', $stockA))->assertStatus(405);
        $this->actingAs($userB)->delete(route('stocks.show', $stockA))->assertStatus(405);

        // Admin: may read, but cannot write (no write route; Livewire writes are blocked by StockPolicy)
        $this->actingAs($admin)->get(route('stocks.show', $stockA))->assertOk();
        $this->actingAs($admin)->patch(route('stocks.show', $stockA))->assertStatus(405);
        $this->actingAs($admin)->delete(route('stocks.show', $stockA))->assertStatus(405);

        $this->assertSame(10, $stockA->fresh()->quantity);
        $this->assertSame('Stock A', $stockA->fresh()->name);
    }

    public function test_member_cannot_see_another_members_stocks_through_admin_routes(): void
    {
        $userA = User::factory()->member()->create();
        $userB = User::factory()->member()->create();

        $this->actingAs($userB)->get("/admin/users/{$userA->id}/stocks")->assertForbidden();
        $this->actingAs($userB)->get('/admin/users')->assertForbidden();
    }

    public function test_member_stocks_route_with_other_users_id_is_forbidden_by_ownership_middleware(): void
    {
        $userA = User::factory()->member()->create();
        $userB = User::factory()->member()->create();

        // {user} route-parameter variant of the ownership middleware
        $route = app('router')->get('/__owns/{user}', fn () => 'ok')->middleware(['web', 'auth', 'owns.stock']);
        $this->assertNotNull($route);

        $this->actingAs($userB)->get("/__owns/{$userA->id}")->assertForbidden();
        $this->actingAs($userA)->get("/__owns/{$userA->id}")->assertOk();
    }

    public function test_nonexistent_stock_returns_404(): void
    {
        $member = User::factory()->member()->create();

        $this->actingAs($member)->get('/stocks/999999')->assertNotFound();
    }

    public function test_root_redirects_by_role(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->member()->create())->get('/')->assertRedirect(route('stocks.index'));
        $this->actingAs(User::factory()->admin()->create())->get('/')->assertRedirect(route('admin.users'));
    }

    public function test_admin_cannot_use_member_stock_list_to_see_other_users_data(): void
    {
        $member = User::factory()->member()->create();
        Stock::factory()->create(['user_id' => $member->id, 'name' => 'Private Member Item']);
        $admin = User::factory()->admin()->create();

        // The admin's own /stocks page only lists the admin's own stock
        $this->actingAs($admin)->get('/stocks')->assertOk()->assertDontSee('Private Member Item');
    }
}
