<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mapeo del enum legacy (valor string) al nombre de la categoría sembrada.
     *
     * @var array<string, string>
     */
    private array $map = [
        'res' => 'Res',
        'cerdo' => 'Cerdo',
        'pollo' => 'Pollo',
        'insumos' => 'Insumos',
        'otro' => 'Otro',
    ];

    /**
     * Reemplaza la columna string `category` (enum) por la FK
     * `purchase_product_category_id`, reasignando los valores existentes a las
     * categorías sembradas por tenant.
     */
    public function up(): void
    {
        Schema::table('purchase_products', function (Blueprint $table) {
            $table->foreignId('purchase_product_category_id')->nullable()->after('unit')
                ->constrained('purchase_product_categories')->nullOnDelete();
        });

        $products = DB::table('purchase_products')->whereNotNull('category')->get(['id', 'tenant_id', 'category']);
        foreach ($products as $product) {
            $name = $this->map[$product->category] ?? null;
            if ($name === null) {
                continue;
            }
            $categoryId = DB::table('purchase_product_categories')
                ->where('tenant_id', $product->tenant_id)
                ->where('name', $name)
                ->value('id');
            if ($categoryId) {
                DB::table('purchase_products')->where('id', $product->id)
                    ->update(['purchase_product_category_id' => $categoryId]);
            }
        }

        Schema::table('purchase_products', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_products', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('unit');
        });

        // Restaura el valor del enum legacy desde el nombre de la categoría.
        $flip = array_flip($this->map);
        $rows = DB::table('purchase_products as p')
            ->join('purchase_product_categories as c', 'c.id', '=', 'p.purchase_product_category_id')
            ->whereNotNull('p.purchase_product_category_id')
            ->get(['p.id', 'c.name']);
        foreach ($rows as $row) {
            $value = $flip[$row->name] ?? null;
            if ($value) {
                DB::table('purchase_products')->where('id', $row->id)->update(['category' => $value]);
            }
        }

        Schema::table('purchase_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_product_category_id');
        });
    }
};
