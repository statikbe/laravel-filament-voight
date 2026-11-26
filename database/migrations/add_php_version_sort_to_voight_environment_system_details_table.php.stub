<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentVoight\Services\VersionKeyRebuilder;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_environment_system_details', 'php_version_sort')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        Schema::table('voight_environment_system_details', function (Blueprint $table) use ($driver) {
            // The key must compare byte by byte, see Statikbe\FilamentVoight\Support\VersionKey.
            $column = $table->string('php_version_sort', 191)->nullable()->after('php_version');

            match ($driver) {
                'mysql', 'mariadb' => $column->charset('ascii')->collation('ascii_bin'),
                'pgsql' => $column->collation('C'),
                default => null,
            };

            $table->index(['php_version_sort'], 'voight_system_details_php_version_sort_index');
        });

        app(VersionKeyRebuilder::class)->rebuild('voight_environment_system_details');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_environment_system_details', 'php_version_sort')) {
            return;
        }

        Schema::table('voight_environment_system_details', function (Blueprint $table) {
            $table->dropIndex('voight_system_details_php_version_sort_index');
            $table->dropColumn('php_version_sort');
        });
    }
};
