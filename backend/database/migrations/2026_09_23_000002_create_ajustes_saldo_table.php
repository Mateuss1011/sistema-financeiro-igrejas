<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajuste de saldo: correção de UMA conta, sempre com valor positivo e `sentido` (crédito ou
     * débito). Imutável: sem edição, exclusão, soft delete nem estorno formal; um ajuste errado é
     * corrigido por outro ajuste de sentido oposto. Justificativa obrigatória (mínimo de 3 caracteres
     * úteis, garantido também no banco). FKs ON DELETE/UPDATE RESTRICT.
     */
    public function up(): void
    {
        Schema::create('ajustes_saldo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas')->restrictOnUpdate()->restrictOnDelete();
            $table->decimal('valor', 14, 2);
            $table->enum('sentido', ['credito', 'debito']);
            $table->date('data_ajuste');
            $table->string('justificativa', 500);
            $table->foreignId('criado_por')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->string('chave_idempotencia', 64)->nullable();
            $table->char('hash_payload', 64)->nullable();
            $table->timestamps();

            $table->unique(['criado_por', 'chave_idempotencia'], 'ajustes_idempotencia_unica');
            $table->index(['conta_id', 'data_ajuste'], 'ajustes_conta_data_idx');
            $table->index('data_ajuste', 'ajustes_data_idx');
        });

        DB::statement(
            "ALTER TABLE `ajustes_saldo` "
            . "ADD CONSTRAINT `chk_ajustes_valor_positivo` CHECK (`valor` > 0), "
            . "ADD CONSTRAINT `chk_ajustes_justificativa_minima` CHECK (CHAR_LENGTH(TRIM(`justificativa`)) >= 3), "
            . "ADD CONSTRAINT `chk_ajustes_idempotencia` CHECK ("
            . "(`chave_idempotencia` IS NULL AND `hash_payload` IS NULL) "
            . "OR (`chave_idempotencia` IS NOT NULL AND `hash_payload` IS NOT NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_saldo');
    }
};
