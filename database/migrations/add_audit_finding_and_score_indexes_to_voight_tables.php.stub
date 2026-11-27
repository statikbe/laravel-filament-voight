<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voight_audit_findings', function (Blueprint $table) {
            $table->index(['audit_run_id', 'vulnerability_id'], 'voight_audit_findings_run_vulnerability_index');
        });

        Schema::table('voight_vulnerabilities', function (Blueprint $table) {
            $table->index('vulnerability_score', 'voight_vulnerabilities_score_index');
        });
    }

    public function down(): void
    {
        Schema::table('voight_audit_findings', function (Blueprint $table) {
            $table->dropIndex('voight_audit_findings_run_vulnerability_index');
        });

        Schema::table('voight_vulnerabilities', function (Blueprint $table) {
            $table->dropIndex('voight_vulnerabilities_score_index');
        });
    }
};
