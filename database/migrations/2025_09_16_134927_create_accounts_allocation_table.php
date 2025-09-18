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
            Schema::create('accounts_allocation', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
                $table->enum('type', ['Darurat', 'Tabungan', 'Kebutuhan']);
                $table->decimal('balance_per_type', 15, 2)->default(0);
                $table->timestamps();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts_allocation');
    }
};
