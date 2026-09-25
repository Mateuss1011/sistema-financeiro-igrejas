<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->enum('tipo', ['banco', 'caixa']);
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->boolean('ativa')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Nome único apenas entre contas não excluídas: vira NULL após o soft delete,
            // liberando o nome para reutilização (NULLs não colidem em índice UNIQUE).
            $table->string('nome_ativo', 100)->nullable()->virtualAs('IF(`deleted_at` IS NULL, `nome`, NULL)');
            $table->unique('nome_ativo');

            $table->index(['tipo', 'ativa']);
        });

        // Caixa físico nunca pode iniciar com saldo negativo (conta bancária pode).
        DB::statement(
            "ALTER TABLE `contas` ADD CONSTRAINT `contas_caixa_saldo_inicial_nao_negativo` "
            . "CHECK (`tipo` <> 'caixa' OR `saldo_inicial` >= 0)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('contas');
    }
};
