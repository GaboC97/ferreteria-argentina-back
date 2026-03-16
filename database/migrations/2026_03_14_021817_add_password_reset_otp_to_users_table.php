<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('reset_password_otp', 10)->nullable()->after('email_otp_expires_at');
            $table->timestamp('reset_password_otp_expires_at')->nullable()->after('reset_password_otp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reset_password_otp', 'reset_password_otp_expires_at']);
        });
    }
};
