<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_audit_runs', 'trigger')) {
            return;
        }

        Schema::table('voight_audit_runs', function (Blueprint $table) {
            $table->string('trigger')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_audit_runs', 'trigger')) {
            return;
        }

        Schema::table('voight_audit_runs', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });
    }
};
