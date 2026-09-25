<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfis', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('nome_exibicao', 60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfis');
    }
};
