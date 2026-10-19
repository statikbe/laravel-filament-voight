<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_teams', 'avatar_path')) {
            return;
        }

        Schema::table('voight_teams', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_teams', 'avatar_path')) {
            return;
        }

        Schema::table('voight_teams', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
