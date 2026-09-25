<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Livro de entradas imutável (sem soft delete): correção só por estorno.
     * O estorno é uma linha nova que aponta para a original (entrada_estornada_id, UNIQUE).
     * Todas as FKs são ON DELETE/UPDATE RESTRICT.
     */
    public function up(): void
    {
        Schema::create('entradas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')
                ->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('conta_id')->constrained('contas')
                ->restrictOnUpdate()->restrictOnDelete();
            $table->decimal('valor', 14, 2);
            $table->date('data_competencia');
            $table->string('descricao', 255)->nullable();
            $table->string('contribuinte_nome', 150)->nullable();
            $table->enum('status', ['confirmada', 'estornada'])->default('confirmada');
            $table->unsignedBigInteger('entrada_estornada_id')->nullable();
            $table->string('motivo_estorno', 500)->nullable();
            $table->foreignId('criado_por')->constrained('users')
                ->restrictOnUpdate()->restrictOnDelete();
            $table->string('chave_idempotencia', 64)->nullable();
            $table->timestamps();

            $table->foreign('entrada_estornada_id')->references('id')->on('entradas')
                ->restrictOnUpdate()->restrictOnDelete();

            $table->unique('entrada_estornada_id', 'entradas_estorno_unico');
            $table->unique(['criado_por', 'chave_idempotencia'], 'entradas_idempotencia_unica');
            $table->index(['conta_id', 'data_competencia'], 'entradas_conta_data_idx');
            $table->index('data_competencia', 'entradas_data_idx');
            $table->index('categoria_id', 'entradas_categoria_idx');
        });

        DB::statement(
            "ALTER TABLE `entradas` "
            . "ADD CONSTRAINT `chk_entradas_valor_positivo` CHECK (`valor` > 0), "
            . "ADD CONSTRAINT `chk_entradas_estorno_coerente` CHECK ("
            . "(`entrada_estornada_id` IS NULL AND `motivo_estorno` IS NULL) "
            . "OR (`entrada_estornada_id` IS NOT NULL AND `motivo_estorno` IS NOT NULL AND `status` = 'confirmada'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('entradas');
    }
};
