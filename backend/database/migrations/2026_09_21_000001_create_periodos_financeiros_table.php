<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 6 só CONSULTA esta tabela (período sem linha = aberto). A Fase 9 cria as linhas
     * ao fechar e as reabre. Os CHECKs já impõem o ciclo válido: fechado sem reabertura, ou
     * aberto após reabertura (com histórico de fechamento e reabertura completo).
     */
    public function up(): void
    {
        Schema::create('periodos_financeiros', function (Blueprint $table) {
            $table->id();
            $table->char('ano_mes', 7)->unique(); // 'YYYY-MM'
            $table->enum('status', ['aberto', 'fechado'])->default('aberto');
            $table->foreignId('fechado_por')->nullable()
                ->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->timestamp('fechado_em')->nullable();
            $table->foreignId('reaberto_por')->nullable()
                ->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->timestamp('reaberto_em')->nullable();
            $table->string('justificativa_reabertura', 500)->nullable();
            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE `periodos_financeiros` "
            . "ADD CONSTRAINT `chk_periodos_ano_mes` "
            . "CHECK (`ano_mes` REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'), "
            . "ADD CONSTRAINT `chk_periodos_ciclo` CHECK ("
            . "(`status` = 'fechado' AND `fechado_por` IS NOT NULL AND `fechado_em` IS NOT NULL "
            . "AND `reaberto_por` IS NULL AND `reaberto_em` IS NULL AND `justificativa_reabertura` IS NULL) "
            . "OR (`status` = 'aberto' AND `fechado_por` IS NOT NULL AND `fechado_em` IS NOT NULL "
            . "AND `reaberto_por` IS NOT NULL AND `reaberto_em` IS NOT NULL AND `justificativa_reabertura` IS NOT NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_financeiros');
    }
};
