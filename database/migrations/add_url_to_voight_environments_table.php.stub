<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_environments', 'url')) {
            return;
        }

        Schema::table('voight_environments', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_environments', 'url')) {
            return;
        }

        Schema::table('voight_environments', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
