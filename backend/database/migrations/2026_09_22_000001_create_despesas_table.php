<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Despesas: uma linha mutável enquanto Pendente (Pendente → Paga | Cancelada) e um livro
     * imutável depois disso. O estorno de uma Paga é uma linha NOVA (status 'paga', valor positivo)
     * que aponta para a original em despesa_estornada_id (UNIQUE); a original passa a 'estornada'.
     *
     * Só linhas com dinheiro efetivamente movido possuem conta_id (garantido pelos CHECKs), então o
     * saldo pode ser derivado de "despesas com conta" sem olhar o status.
     * Sem soft delete (a exclusão física só existe para Pendentes, por regra de negócio).
     * Todas as FKs são ON DELETE/UPDATE RESTRICT. DDL validado em sfg_testing (133 verificações).
     */
    public function up(): void
    {
        Schema::create('despesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('conta_id')->nullable()->constrained('contas')->restrictOnUpdate()->restrictOnDelete();
            $table->decimal('valor', 14, 2);
            $table->date('data_competencia');
            $table->date('data_pagamento')->nullable();
            $table->string('descricao', 255)->nullable();
            $table->string('fornecedor_nome', 150)->nullable();
            $table->enum('status', ['pendente', 'paga', 'estornada', 'cancelada'])->default('pendente');
            $table->unsignedBigInteger('despesa_estornada_id')->nullable();
            $table->string('motivo_estorno', 500)->nullable();
            $table->string('motivo_cancelamento', 500)->nullable();
            $table->foreignId('criado_por')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('atualizado_por')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('pago_por')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->timestamp('pago_em')->nullable();
            $table->string('chave_idempotencia', 64)->nullable();
            $table->char('hash_payload', 64)->nullable();
            $table->timestamps();

            $table->foreign('despesa_estornada_id')->references('id')->on('despesas')->restrictOnUpdate()->restrictOnDelete();

            $table->unique('despesa_estornada_id', 'despesas_estorno_unico');
            $table->unique(['criado_por', 'chave_idempotencia'], 'despesas_idempotencia_unica');
            $table->index(['conta_id', 'data_pagamento'], 'despesas_conta_pagamento_idx');
            $table->index('data_competencia', 'despesas_competencia_idx');
            $table->index('categoria_id', 'despesas_categoria_idx');
            $table->index(['status', 'data_competencia'], 'despesas_status_competencia_idx');
        });

        DB::statement(
            "ALTER TABLE `despesas` "
            . "ADD CONSTRAINT `chk_despesas_valor_positivo` CHECK (`valor` > 0), "
            . "ADD CONSTRAINT `chk_despesas_original_coerente` CHECK (`despesa_estornada_id` IS NOT NULL OR ("
            . "(`status` = 'pendente' AND `conta_id` IS NULL AND `data_pagamento` IS NULL AND `pago_por` IS NULL AND `pago_em` IS NULL AND `motivo_cancelamento` IS NULL AND `motivo_estorno` IS NULL) "
            . "OR (`status` IN ('paga','estornada') AND `conta_id` IS NOT NULL AND `data_pagamento` IS NOT NULL AND `pago_por` IS NOT NULL AND `pago_em` IS NOT NULL AND `motivo_cancelamento` IS NULL AND `motivo_estorno` IS NULL) "
            . "OR (`status` = 'cancelada' AND `conta_id` IS NULL AND `data_pagamento` IS NULL AND `pago_por` IS NULL AND `pago_em` IS NULL AND `motivo_cancelamento` IS NOT NULL AND `motivo_estorno` IS NULL))), "
            . "ADD CONSTRAINT `chk_despesas_estorno_coerente` CHECK (`despesa_estornada_id` IS NULL OR (`status` = 'paga' AND `conta_id` IS NOT NULL AND `data_pagamento` IS NOT NULL AND `motivo_estorno` IS NOT NULL AND `motivo_cancelamento` IS NULL AND `pago_por` IS NULL AND `pago_em` IS NULL)), "
            . "ADD CONSTRAINT `chk_despesas_idempotencia` CHECK ((`chave_idempotencia` IS NULL AND `hash_payload` IS NULL) OR (`chave_idempotencia` IS NOT NULL AND `hash_payload` IS NOT NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('despesas');
    }
};
