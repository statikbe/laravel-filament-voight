<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentVoight\Services\VersionKeyRebuilder;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_environment_packages', 'version_sort')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        Schema::table('voight_environment_packages', function (Blueprint $table) use ($driver) {
            // The key must compare byte by byte, see Statikbe\FilamentVoight\Support\VersionKey.
            $column = $table->string('version_sort', 191)->nullable()->after('version');

            match ($driver) {
                'mysql', 'mariadb' => $column->charset('ascii')->collation('ascii_bin'),
                'pgsql' => $column->collation('C'),
                default => null,
            };

            $table->index(['package_id', 'version_sort'], 'voight_env_packages_version_sort_index');
        });

        app(VersionKeyRebuilder::class)->rebuild('voight_environment_packages');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_environment_packages', 'version_sort')) {
            return;
        }

        Schema::table('voight_environment_packages', function (Blueprint $table) {
            $table->dropIndex('voight_env_packages_version_sort_index');
            $table->dropColumn('version_sort');
        });
    }
};
