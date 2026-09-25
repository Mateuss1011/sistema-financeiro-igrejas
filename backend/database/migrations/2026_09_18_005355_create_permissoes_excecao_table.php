<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissoes_excecao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permissao', 100);
            $table->foreignId('concedida_por')->constrained('users')->restrictOnDelete();
            $table->timestamp('criado_em')->useCurrent();

            $table->unique(['user_id', 'permissao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissoes_excecao');
    }
};
