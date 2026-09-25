<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->enum('tipo', ['entrada', 'despesa']);
            $table->boolean('ativa')->default(true);
            $table->timestamps();

            $table->unique(['tipo', 'nome']);
            $table->index(['tipo', 'ativa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
