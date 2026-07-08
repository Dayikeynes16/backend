<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\User;
use App\Services\Ai\Assistant\Drafts\ToolContext;
use App\Services\Ai\Assistant\Tools\PrepareProductPriceDraftTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * F5 (D7): cambio de precio base vía borrador — solo admin-sucursal, solo
 * productos de su sucursal, solo el campo price.
 */
class AssistantProductPriceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function tool(): PrepareProductPriceDraftTool
    {
        return app(PrepareProductPriceDraftTool::class);
    }

    private function makeDraft(User $user): AssistantDraft
    {
        $session = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'message_count' => 0,
        ]);
        $msg = AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => 'sube el bistec a 240',
        ]);

        return AssistantDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::PriceChange->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['new_price' => 240],
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function confirmUrl(AssistantDraft $draft): string
    {
        return route('asistente.drafts.confirm', ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_tool_resolves_product_and_flags_below_cost(): void
    {
        $product = $this->makeProduct(['name' => 'Bistec de res', 'price' => 220, 'cost_price' => 150]);

        $params = $this->tool()->validate($this->adminSucursal, ['product_name' => 'bistec de res', 'new_price' => 240]);
        $this->assertSame($product->id, $params['product_id']);
        $this->assertEqualsWithDelta(220.0, $params['product']['current_price'], 0.001);

        // Precio por debajo del costo → warning.
        $belowCost = $this->tool()->validate($this->adminSucursal, ['product_name' => 'Bistec de res', 'new_price' => 100]);
        $result = $this->tool()->prepareDraft($this->adminSucursal, $belowCost, $this->context($this->adminSucursal));
        $this->assertNotEmpty($result->data['warnings']);
    }

    public function test_tool_does_not_see_other_branch_products(): void
    {
        $this->makeProduct(['name' => 'Chuleta', 'branch_id' => $this->secondBranch->id]);

        $params = $this->tool()->validate($this->adminSucursal, ['product_name' => 'Chuleta', 'new_price' => 100]);

        $this->assertNull($params['product_id']);
        $this->assertSame([], $params['product_candidates']);
    }

    public function test_confirm_updates_only_price(): void
    {
        $product = $this->makeProduct(['name' => 'Bistec de res', 'price' => 220, 'cost_price' => 150]);
        $draft = $this->makeDraft($this->adminSucursal);

        $response = $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), ['product_id' => $product->id, 'new_price' => 240])
            ->assertOk();

        $product->refresh();
        $this->assertEqualsWithDelta(240.0, (float) $product->price, 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $product->cost_price, 0.001);
        $this->assertSame('Bistec de res', $product->name);
        $this->assertStringContainsString('220.00', $response->json('message'));
        $this->assertStringContainsString('240.00', $response->json('message'));

        $this->assertSame(AiDraftStatus::Consumed, $draft->refresh()->status);
    }

    public function test_confirm_rejects_other_branch_product(): void
    {
        $other = $this->makeProduct(['name' => 'Chuleta', 'branch_id' => $this->secondBranch->id, 'price' => 100]);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), ['product_id' => $other->id, 'new_price' => 240])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(100.0, (float) $other->fresh()->price, 0.001);
    }

    public function test_cajero_cannot_confirm_price_changes(): void
    {
        $product = $this->makeProduct(['name' => 'Bistec de res', 'price' => 220]);
        $draft = $this->makeDraft($this->cajero);

        $this->actingAs($this->cajero)
            ->postJson($this->confirmUrl($draft), ['product_id' => $product->id, 'new_price' => 240])
            ->assertForbidden();

        $this->assertEqualsWithDelta(220.0, (float) $product->fresh()->price, 0.001);
    }

    private function context(User $user): ToolContext
    {
        $session = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'message_count' => 0,
        ]);
        $msg = AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => 'cambia el precio',
        ]);

        return new ToolContext($session, $msg, []);
    }
}
