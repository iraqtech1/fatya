<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('stock_category_codes')->get();
        $products = DB::table('products')->get()->mapWithKeys(fn ($r) => [$r->id => json_decode($r->payload, true)]);
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // Shared-hosting accounts need ALTER/INDEX, without granting DROP or renaming live tables.
            if (! Schema::hasColumn('stock_category_codes', 'scope')) {
                Schema::table('stock_category_codes', fn (Blueprint $t) => $t->string('scope', 110)->default('*'));
            }
            foreach ($rows as $r) {
                $p = $products[$r->product_id] ?? [];
                $scope = empty($p['manufacturerId']) ? '*' : 'manufacturer:'.$p['manufacturerId'];
                DB::table('stock_category_codes')->where('code', $r->code)->where('product_id', $r->product_id)->update(['scope' => $scope]);
            }
            $primary = DB::select("SHOW INDEX FROM stock_category_codes WHERE Key_name = 'PRIMARY'");
            usort($primary, fn ($a, $b) => $a->Seq_in_index <=> $b->Seq_in_index);
            if (array_map(fn ($r) => $r->Column_name, $primary) !== ['scope', 'code']) {
                DB::statement('ALTER TABLE stock_category_codes DROP PRIMARY KEY, ADD PRIMARY KEY (scope, code)');
            }

            return;
        }
        Schema::rename('stock_category_codes', 'stock_category_codes_old');
        try {
            Schema::create('stock_category_codes', function (Blueprint $t) {
                $t->string('code', 40);
                $t->string('scope', 110)->default('*');
                $t->string('product_id', 100);
                $t->decimal('denomination', 20, 3);
                $t->primary(['scope', 'code']);
                // product IDs are validated by the catalog service; keep old tables' FK name free until drop.
                $t->foreign('product_id', 'stock_codes_scoped_product_fk')->references('id')->on('products');
            });
            foreach ($rows as $r) {
                $p = $products[$r->product_id] ?? [];
                $scope = empty($p['manufacturerId']) ? '*' : 'manufacturer:'.$p['manufacturerId'];
                DB::table('stock_category_codes')->insert(['code' => $r->code, 'scope' => $scope, 'product_id' => $r->product_id, 'denomination' => $r->denomination]);
            }
            Schema::drop('stock_category_codes_old');
        } catch (Throwable $e) {
            if (Schema::hasTable('stock_category_codes_old')) {
                Schema::dropIfExists('stock_category_codes');
                Schema::rename('stock_category_codes_old', 'stock_category_codes');
            }
            throw $e;
        }
    }

    public function down(): void
    {
        if (DB::table('stock_category_codes')->select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Same-code company mappings require the verified backup to reverse safely.');
        }
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE stock_category_codes DROP PRIMARY KEY, ADD PRIMARY KEY (code)');

            return;
        }
        $rows = DB::table('stock_category_codes')->get(['code', 'product_id', 'denomination']);
        Schema::create('stock_category_codes_revert', function (Blueprint $t) {
            $t->string('code', 40)->primary();
            $t->string('product_id', 100);
            $t->decimal('denomination', 20, 3);
            $t->foreign('product_id', 'stock_codes_revert_product_fk')->references('id')->on('products');
        });
        foreach ($rows as $r) {
            DB::table('stock_category_codes_revert')->insert((array) $r);
        }
        Schema::drop('stock_category_codes');
        Schema::rename('stock_category_codes_revert', 'stock_category_codes');
    }
};
