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
        Schema::create('incomes', function (Blueprint $table) {
        $table->id();

        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('account_id');
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');

        $table->boolean('is_manual')->default(false);

        $table->enum('frequency', ['Harian', 'Mingguan', 'Bulanan', 'Tahunan', 'Sekali'])->nullable();

        $table->decimal('amount', 15, 2);

        $table->enum('income_source', ['Gaji', 'Uang Saku', 'Uang Kaget', 'Hadiah', 'Lainnya']);

        $table->enum('confirmation_status', ['Pending', 'Confirmed', 'Rejected'])->default('Pending');
        $table->decimal('actual_amount', 15, 2)->nullable();

        $table->text('note')->nullable();
        $table->date('received_date');

        $table->timestamps();
    });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
