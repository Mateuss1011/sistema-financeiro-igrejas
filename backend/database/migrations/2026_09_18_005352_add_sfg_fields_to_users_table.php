<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('perfil_id')->after('id')->constrained('perfis')->restrictOnDelete();
            $table->boolean('ativo')->default(true)->after('password');
            $table->timestamp('ultimo_login_em')->nullable()->after('ativo');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_id');
            $table->dropColumn(['ativo', 'ultimo_login_em']);
            $table->dropSoftDeletes();
        });
    }
};
