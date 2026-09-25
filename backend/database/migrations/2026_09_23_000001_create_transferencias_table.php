<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transferência entre contas: UMA linha = UMA operação (soma na destino e subtrai na origem).
     * Imutável (sem soft delete): o estorno é uma linha NOVA em sentido inverso (origem e destino
     * trocados), vinculada à original por transferencia_estornada_id (UNIQUE); a original passa a
     * 'estornada'. O saldo das contas é derivado destas linhas; nada é persistido como saldo.
     * Todas as FKs são ON DELETE/UPDATE RESTRICT.
     */
    public function up(): void
    {
        Schema::create('transferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_origem_id')->constrained('contas')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('conta_destino_id')->constrained('contas')->restrictOnUpdate()->restrictOnDelete();
            $table->decimal('valor', 14, 2);
            $table->date('data_transferencia');
            $table->string('descricao', 255)->nullable();
            $table->enum('status', ['confirmada', 'estornada'])->default('confirmada');
            $table->unsignedBigInteger('transferencia_estornada_id')->nullable();
            $table->string('motivo_estorno', 500)->nullable();
            $table->foreignId('criado_por')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->string('chave_idempotencia', 64)->nullable();
            $table->char('hash_payload', 64)->nullable();
            $table->timestamps();

            $table->foreign('transferencia_estornada_id')->references('id')->on('transferencias')->restrictOnUpdate()->restrictOnDelete();

            $table->unique('transferencia_estornada_id', 'transferencias_estorno_unico');
            $table->unique(['criado_por', 'chave_idempotencia'], 'transferencias_idempotencia_unica');
            $table->index(['conta_origem_id', 'data_transferencia'], 'transferencias_origem_data_idx');
            $table->index(['conta_destino_id', 'data_transferencia'], 'transferencias_destino_data_idx');
            $table->index('data_transferencia', 'transferencias_data_idx');
        });

        DB::statement(
            "ALTER TABLE `transferencias` "
            . "ADD CONSTRAINT `chk_transferencias_valor_positivo` CHECK (`valor` > 0), "
            . "ADD CONSTRAINT `chk_transferencias_contas_distintas` CHECK (`conta_origem_id` <> `conta_destino_id`), "
            . "ADD CONSTRAINT `chk_transferencias_estorno_coerente` CHECK ("
            . "(`transferencia_estornada_id` IS NULL AND `motivo_estorno` IS NULL) "
            . "OR (`transferencia_estornada_id` IS NOT NULL AND `motivo_estorno` IS NOT NULL AND `status` = 'confirmada')), "
            . "ADD CONSTRAINT `chk_transferencias_idempotencia` CHECK ("
            . "(`chave_idempotencia` IS NULL AND `hash_payload` IS NULL) "
            . "OR (`chave_idempotencia` IS NOT NULL AND `hash_payload` IS NOT NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencias');
    }
};
