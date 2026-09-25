<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_nome_congelado', 150)->nullable();
            $table->string('user_perfil_congelado', 30)->nullable();
            $table->string('acao', 60);
            $table->string('modulo', 60);
            $table->unsignedBigInteger('registro_id')->nullable();
            $table->json('dados_anteriores')->nullable();
            $table->json('dados_novos')->nullable();
            $table->text('justificativa')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index(['modulo', 'registro_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
