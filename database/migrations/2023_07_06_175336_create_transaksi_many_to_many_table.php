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
        Schema::create('transaksi_many_to_many', function (Blueprint $table) {
            $table->id();
            $table->string('kode_transaksi');
            $table->date('tanggal');
            $table->bigInteger('kode_akun');
            $table->enum('tipe',['masuk','keluar']);
            $table->bigInteger('total');
            $table->text('keterangan');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaksi_many_to_many');
    }
};
