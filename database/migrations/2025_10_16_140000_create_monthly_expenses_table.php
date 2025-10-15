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
        Schema::create('monthly_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('expense_category_id')->constrained('expense_categories')->onDelete('cascade');
            $table->decimal('total_amount', 15, 2); // Total budget untuk kategori ini per bulan
            $table->decimal('current_amount', 15, 2); // Sisa budget yang tersedia
            $table->decimal('used_amount', 15, 2)->default(0); // Total yang sudah digunakan
            $table->integer('month'); // 1-12
            $table->integer('year'); // 2024, 2025, etc
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            // Index untuk performa query
            $table->index(['user_id', 'month', 'year']);
            $table->unique(['user_id', 'expense_category_id', 'month', 'year']); // Prevent duplicate category per month
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_expenses');
    }
};