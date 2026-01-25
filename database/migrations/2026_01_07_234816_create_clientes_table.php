<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 120);
            $table->string('apellido', 120)->nullable();
            $table->string('email', 160)->unique();
            $table->string('telefono', 40)->nullable();

            // Para login (cuenta opcional). Si más adelante querés guest-only, igual no molesta.
            $table->string('password_hash', 255)->nullable();

            $table->boolean('activo')->default(true);

            // Para “recordarme” en el checkout y autocompletar (si querés)
            $table->timestamp('email_verificado_en')->nullable();

            $table->timestamps();

            $table->index(['activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
