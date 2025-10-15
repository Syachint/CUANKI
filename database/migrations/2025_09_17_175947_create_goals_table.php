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
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_allocation_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('account_allocation_id')->references('id')->on('accounts_allocation')->onDelete('cascade');
            $table->boolean('is_first')->default(false);
            $table->decimal('target_amount', 15, 2); // NOT NULL - wajib diisi dari input user
            $table->date('target_deadline')->nullable(); // NULLABLE - bisa tanpa tenggat waktu
            $table->string('goal_name');
            // goal_amount DIHAPUS - current amount diambil dari balance_per_type
            $table->boolean('is_goal_achieved')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
