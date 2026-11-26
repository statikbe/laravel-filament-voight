<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_audit_findings', 'installed_version_sort')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        Schema::table('voight_audit_findings', function (Blueprint $table) use ($driver) {
            // The key must compare byte by byte, see Statikbe\FilamentVoight\Support\VersionKey.
            $column = $table->string('installed_version_sort', 191)->nullable()->after('installed_version');

            match ($driver) {
                'mysql', 'mariadb' => $column->charset('ascii')->collation('ascii_bin'),
                'pgsql' => $column->collation('C'),
                default => null,
            };

            $table->index(['package_id', 'installed_version_sort'], 'voight_audit_findings_version_sort_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_audit_findings', 'installed_version_sort')) {
            return;
        }

        Schema::table('voight_audit_findings', function (Blueprint $table) {
            $table->dropIndex('voight_audit_findings_version_sort_index');
            $table->dropColumn('installed_version_sort');
        });
    }
};
