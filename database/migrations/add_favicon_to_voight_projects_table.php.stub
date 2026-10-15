<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_projects', 'favicon_path')) {
            return;
        }

        Schema::table('voight_projects', function (Blueprint $table) {
            $table->string('favicon_path')->nullable()->after('repo_url');
            $table->timestamp('favicon_fetched_at')->nullable()->after('favicon_path');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_projects', 'favicon_path')) {
            return;
        }

        Schema::table('voight_projects', function (Blueprint $table) {
            $table->dropColumn(['favicon_path', 'favicon_fetched_at']);
        });
    }
};
