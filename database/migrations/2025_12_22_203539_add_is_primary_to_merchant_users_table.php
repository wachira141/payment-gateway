<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_users', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
