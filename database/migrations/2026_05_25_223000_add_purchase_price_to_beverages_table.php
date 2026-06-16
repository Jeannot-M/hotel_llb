<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('beverages', function (Blueprint $table) {
            $table->integer('purchase_price')->default(0)->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('beverages', function (Blueprint $table) {
            $table->dropColumn('purchase_price');
        });
    }
};
