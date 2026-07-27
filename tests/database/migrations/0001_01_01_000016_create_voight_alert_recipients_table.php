<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_alert_recipients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('alert_setting_id')->constrained('voight_alert_settings')->cascadeOnDelete();
            $table->string('recipient_type');
            $table->string('recipient_id');
            $table->timestamps();

            $table->index(['recipient_type', 'recipient_id']);
            $table->unique(['alert_setting_id', 'recipient_type', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_alert_recipients');
    }
};
