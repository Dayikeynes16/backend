<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiCategoryDraft;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CategoryDraftControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');
    }

    private function fakeOpenAiCreateNew(array $proposal = []): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 80],
                'choices' => [[
                    'message' => ['content' => json_encode(array_merge([
                        'accion_sugerida' => 'crear_categoria',
                        'categoria_similar_existente' => null,
                        'nombre_categoria' => 'Transporte y vehículos',
                        'descripcion' => 'Vehículos, gasolina, mantenimiento, reparto.',
                        'aliases' => ['Vehículos', 'Camionetas', 'Reparto'],
                        'incluye' => ['Gasolina', 'Mantenimiento', 'Llantas'],
                        'no_incluye' => ['Gastos personales', 'Mercancía'],
                        'subcategorias_sugeridas' => [
                            ['nombre' => 'Combustible', 'descripcion' => 'Gasolina y diésel', 'aliases' => ['Diésel'], 'incluye' => [], 'no_incluye' => []],
                            ['nombre' => 'Mantenimiento', 'descripcion' => 'Servicios mecánicos', 'aliases' => [], 'incluye' => [], 'no_incluye' => []],
                        ],
                        'confianza' => 'alta',
                        'preguntas_faltantes' => [],
                    ], $proposal))],
                ]],
            ], 200),
        ]);
    }

    public function test_admin_empresa_can_create_draft_with_text_only(): void
    {
        $this->fakeOpenAiCreateNew();

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'Quiero una categoría para gasolina, camionetas y reparto',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.action', 'crear_categoria')
            ->assertJsonPath('proposal.category.name', 'Transporte y vehículos')
            ->assertJsonPath('proposal.subcategories.0.name', 'Combustible')
            ->assertJsonPath('proposal.confidence', 'alta');

        $draft = AiCategoryDraft::firstOrFail();
        $this->assertSame(AiDraftStatus::Ready, $draft->status);
        $this->assertSame($this->adminEmpresa->id, $draft->user_id);
        $this->assertSame(200, $draft->prompt_tokens);
    }

    public function test_admin_sucursal_cannot_create_category_draft(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'foo',
        ])->assertForbidden();
    }

    public function test_invalid_existing_category_id_falls_back_to_create_new(): void
    {
        // La IA dice "usar_existente" con un id que no existe → debe degradar a "crear nueva".
        $this->fakeOpenAiCreateNew([
            'accion_sugerida' => 'usar_existente',
            'categoria_similar_existente' => ['id' => 99999, 'nombre' => 'Inventada', 'razon' => 'x'],
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'algo',
        ]);

        $response->assertOk()->assertJsonPath('proposal.action', 'crear_categoria');

        $draft = AiCategoryDraft::firstOrFail();
        $this->assertNotEmpty($draft->parsed_proposal['alerts']);
    }

    public function test_use_existing_proposes_reuse_of_real_category(): void
    {
        $existing = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'description' => 'Vehículos',
            'aliases' => ['Vehículos'],
            'status' => 'active',
        ]);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'accion_sugerida' => 'usar_existente',
                        'categoria_similar_existente' => ['id' => $existing->id, 'nombre' => 'Transporte', 'razon' => 'Ya cubre vehículos'],
                        'mejoras_sugeridas' => [
                            'descripcion' => 'Vehículos, gasolina, mantenimiento',
                            'aliases_a_agregar' => ['Diésel', 'Camionetas'],
                            'includes_a_agregar' => ['Llantas'],
                        ],
                        'subcategorias_sugeridas' => [
                            ['nombre' => 'Refacciones', 'descripcion' => 'Piezas', 'aliases' => [], 'incluye' => [], 'no_incluye' => []],
                        ],
                        'confianza' => 'alta',
                    ])],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'Quiero gestionar gastos de vehículos',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.action', 'usar_existente')
            ->assertJsonPath('proposal.existing_category.id', $existing->id)
            ->assertJsonPath('proposal.improvements.aliases_to_add.0', 'Diésel');
    }

    public function test_apply_create_new_persists_category_and_subcategories(): void
    {
        $draft = AiCategoryDraft::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'status' => AiDraftStatus::Ready->value,
            'parsed_proposal' => [],
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.apply', $this->tenant->slug), [
            'ai_draft_id' => $draft->id,
            'mode' => 'create_new',
            'category' => [
                'name' => 'Transporte y vehículos',
                'description' => 'Gastos de vehículos.',
                'aliases' => ['Vehículos', 'Camionetas'],
                'includes' => ['Gasolina', 'Mantenimiento'],
                'excludes' => ['Mercancía'],
            ],
            'subcategories' => [
                ['name' => 'Combustible', 'description' => 'Gasolina y diésel', 'aliases' => ['Diésel'], 'includes' => [], 'excludes' => []],
                ['name' => 'Mantenimiento', 'description' => 'Reparaciones', 'aliases' => [], 'includes' => [], 'excludes' => []],
            ],
        ]);

        $response->assertOk();

        $cat = ExpenseCategory::firstOrFail();
        $this->assertSame('Transporte y vehículos', $cat->name);
        $this->assertSame(['Vehículos', 'Camionetas'], $cat->aliases);
        $this->assertSame(['Gasolina', 'Mantenimiento'], $cat->includes);
        $this->assertSame(['Mercancía'], $cat->excludes);
        $this->assertSame(2, $cat->subcategories()->count());

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
        $this->assertSame($cat->id, $draft->expense_category_id);
    }

    public function test_apply_use_existing_merges_arrays_and_adds_subcategories(): void
    {
        $cat = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'description' => 'Original',
            'aliases' => ['Vehículos'],
            'includes' => ['Gasolina'],
            'excludes' => [],
            'status' => 'active',
        ]);
        ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Combustible',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.apply', $this->tenant->slug), [
            'mode' => 'use_existing',
            'existing_category_id' => $cat->id,
            'category_updates' => [
                'description' => 'Mejorada',
                'aliases_to_add' => ['Diésel', 'vehículos'], // 'vehículos' es dupe case-insensitive
                'includes_to_add' => ['Llantas'],
                'excludes_to_add' => ['Mercancía'],
            ],
            'subcategories' => [
                ['name' => 'Refacciones'],
            ],
        ]);

        $response->assertOk();

        $cat->refresh();
        $this->assertSame('Mejorada', $cat->description);
        // Dedupe case-insensitive: 'Vehículos' (original) + 'Diésel' (nuevo, 'vehículos' descartado).
        $this->assertSame(['Vehículos', 'Diésel'], $cat->aliases);
        $this->assertSame(['Gasolina', 'Llantas'], $cat->includes);
        $this->assertSame(['Mercancía'], $cat->excludes);
        $this->assertSame(2, $cat->subcategories()->count());
    }

    public function test_apply_rejects_duplicate_subcategory_within_same_payload(): void
    {
        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.apply', $this->tenant->slug), [
            'mode' => 'create_new',
            'category' => ['name' => 'Test'],
            'subcategories' => [
                ['name' => 'Gasolina'],
                ['name' => 'gasolina'], // duplicado case-insensitive
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ExpenseCategory::count());
    }

    public function test_apply_rejects_subcategory_that_already_exists_in_target_category(): void
    {
        $cat = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'status' => 'active',
        ]);
        ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Gasolina',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.apply', $this->tenant->slug), [
            'mode' => 'use_existing',
            'existing_category_id' => $cat->id,
            'subcategories' => [
                ['name' => 'GASOLINA'], // ya existe (case-insensitive)
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, $cat->subcategories()->count()); // sin cambios
    }

    public function test_apply_rejects_duplicate_category_name(): void
    {
        ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.gastos.categorias.ia.apply', $this->tenant->slug), [
            'mode' => 'create_new',
            'category' => ['name' => 'Transporte'],
        ])->assertStatus(422);
    }

    public function test_apply_with_cross_tenant_draft_id_ignores_draft_but_creates_category(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-cat', 'status' => 'active']);
        $foreignDraft = AiCategoryDraft::create([
            'tenant_id' => $other->id,
            'user_id' => $this->adminEmpresa->id,
            'status' => AiDraftStatus::Ready->value,
            'parsed_proposal' => [],
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.gastos.categorias.ia.apply', $this->tenant->slug), [
            'ai_draft_id' => $foreignDraft->id,
            'mode' => 'create_new',
            'category' => ['name' => 'Algo'],
        ])->assertOk();

        // El draft ajeno no se toca.
        $foreignDraft->refresh();
        $this->assertSame(AiDraftStatus::Ready, $foreignDraft->status);
        $this->assertNull($foreignDraft->expense_category_id);
    }

    public function test_admin_sucursal_cannot_apply(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->postJson(route('empresa.gastos.categorias.ia.apply', $this->tenant->slug), [
            'mode' => 'create_new',
            'category' => ['name' => 'X'],
        ])->assertForbidden();
    }

    public function test_draft_with_audio_transcribes_and_includes_in_response(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/audio/transcriptions' => Http::response(['text' => 'gasolina y reparto'], 200),
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'accion_sugerida' => 'crear_categoria',
                        'nombre_categoria' => 'Logística',
                        'descripcion' => 'x',
                        'confianza' => 'media',
                    ])],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'audio' => UploadedFile::fake()->createWithContent('voice.webm', str_repeat('a', 1024)),
        ]);

        $response->assertOk()->assertJsonPath('audio_transcription', 'gasolina y reparto');

        $draft = AiCategoryDraft::firstOrFail();
        $this->assertNotNull($draft->audio_path);
        $this->assertSame('gasolina y reparto', $draft->audio_transcription);

        Http::assertSentInOrder([
            fn ($req) => str_contains($req->url(), '/audio/transcriptions'),
            fn ($req) => str_contains($req->url(), '/chat/completions'),
        ]);
    }

    public function test_draft_requires_text_or_audio(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [])
            ->assertStatus(422);
    }

    public function test_crear_subcategoria_resolves_parent_and_proposed_subcategory(): void
    {
        $parent = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'status' => 'active',
        ]);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'accion_sugerida' => 'crear_subcategoria',
                        'categoria_padre' => ['id' => $parent->id, 'nombre' => 'Transporte', 'razon' => 'Gasolina es un tipo de transporte'],
                        'subcategoria_propuesta' => [
                            'nombre' => 'Combustible',
                            'descripcion' => 'Gasolina y diésel',
                            'aliases' => ['Gasolina', 'Diésel'],
                            'incluye' => ['Magna', 'Premium'],
                            'no_incluye' => [],
                        ],
                        'subcategoria_similar_existente' => null,
                        'confianza' => 'alta',
                    ])],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'quiero una categoría de gasolina',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.action', 'crear_subcategoria')
            ->assertJsonPath('proposal.parent_category.id', $parent->id)
            ->assertJsonPath('proposal.parent_category.name', 'Transporte')
            ->assertJsonPath('proposal.subcategories.0.name', 'Combustible')
            ->assertJsonPath('proposal.subcategories.0.aliases.0', 'Gasolina')
            ->assertJsonPath('proposal.similar_subcategory', null);
    }

    public function test_crear_subcategoria_with_invalid_parent_degrades_to_crear_categoria(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'accion_sugerida' => 'crear_subcategoria',
                        'categoria_padre' => ['id' => 99999, 'nombre' => 'Inexistente', 'razon' => 'x'],
                        'subcategoria_propuesta' => ['nombre' => 'X'],
                        'confianza' => 'media',
                    ])],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'foo',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.action', 'crear_categoria')
            ->assertJsonPath('proposal.parent_category', null);

        $draft = AiCategoryDraft::firstOrFail();
        $this->assertNotEmpty($draft->parsed_proposal['alerts']);
    }

    public function test_crear_subcategoria_surfaces_similar_existing_subcategory_in_parent(): void
    {
        $parent = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte',
            'status' => 'active',
        ]);
        $existingSub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $parent->id,
            'name' => 'Combustible',
            'status' => 'active',
        ]);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'accion_sugerida' => 'crear_subcategoria',
                        'categoria_padre' => ['id' => $parent->id, 'nombre' => 'Transporte', 'razon' => 'x'],
                        'subcategoria_propuesta' => ['nombre' => 'Gasolina', 'descripcion' => 'Carga de combustible'],
                        'subcategoria_similar_existente' => [
                            'id' => $existingSub->id,
                            'nombre' => 'Combustible',
                            'razon' => 'Ya cubre el mismo concepto',
                        ],
                        'confianza' => 'alta',
                    ])],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'gasolina',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.action', 'crear_subcategoria')
            ->assertJsonPath('proposal.similar_subcategory.id', $existingSub->id)
            ->assertJsonPath('proposal.similar_subcategory.name', 'Combustible');
    }

    public function test_similar_subcategory_from_different_parent_is_ignored(): void
    {
        $parentA = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Transporte', 'status' => 'active',
        ]);
        $parentB = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Insumos', 'status' => 'active',
        ]);
        $foreignSub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $parentB->id,    // pertenece a Insumos, no a Transporte
            'name' => 'Gasolina',
            'status' => 'active',
        ]);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'accion_sugerida' => 'crear_subcategoria',
                        'categoria_padre' => ['id' => $parentA->id, 'nombre' => 'Transporte', 'razon' => 'x'],
                        'subcategoria_propuesta' => ['nombre' => 'Combustible'],
                        // Id de una subcategoría que existe pero pertenece a Insumos, no a Transporte:
                        'subcategoria_similar_existente' => ['id' => $foreignSub->id, 'nombre' => 'X', 'razon' => 'y'],
                        'confianza' => 'alta',
                    ])],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(route('empresa.gastos.categorias.ia.store', $this->tenant->slug), [
            'input_text' => 'gasolina',
        ]);

        // similar_subcategory debe ser null porque la subcategoría no pertenece al parent propuesto.
        $response->assertOk()->assertJsonPath('proposal.similar_subcategory', null);
    }
}
