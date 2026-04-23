<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_alert_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('project_id')->nullable()->constrained('voight_projects')->cascadeOnDelete();
            $table->string('channel');
            $table->decimal('severity_threshold', 3, 1);
            $table->string('frequency');
            $table->string('webhook_url')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_alert_settings');
    }
};
