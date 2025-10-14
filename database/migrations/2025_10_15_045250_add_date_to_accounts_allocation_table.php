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
        Schema::table('accounts_allocation', function (Blueprint $table) {
            $table->date('allocation_date')->after('balance_per_type')->default(now()->toDateString());
            $table->index(['account_id', 'type', 'allocation_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts_allocation', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'type', 'allocation_date']);
            $table->dropColumn('allocation_date');
        });
    }
};
