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

        // relasi user & account
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('account_id');
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');

        // income otomatis / manual
        $table->boolean('is_manual')->default(false);

        // frekuensi income (nullable biar manual ga wajib isi)
        $table->enum('frequency', ['Harian', 'Mingguan', 'Bulanan', 'Tahunan', 'Sekali'])->nullable();

        // nominal rencana (target)
        $table->decimal('amount', 15, 2);

        // sumber income
        $table->enum('income_source', ['Gaji', 'Uang Saku', 'Uang Kaget', 'Hadiah', 'Lainnya']);

        // konfirmasi income
        $table->enum('confirmation_status', ['Pending', 'Confirmed', 'Rejected'])->default('Pending');
        $table->decimal('actual_amount', 15, 2)->nullable(); // nominal real dari user saat confirm

        // tambahan
        $table->text('note')->nullable();
        $table->date('received_date'); // tanggal terima income

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
