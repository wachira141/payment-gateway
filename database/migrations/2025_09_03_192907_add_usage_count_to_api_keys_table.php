<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            //usage count column
            $table->unsignedBigInteger('usage_count')->default(0)->after('metadata')->comment('Total number of times this API key has been used');
        });

     
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('usage_count');
        });
    }
};