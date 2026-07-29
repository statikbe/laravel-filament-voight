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

            // Named explicitly: the generated names would exceed MySQL's 64-character
            // identifier limit. SQLite does not enforce it, so this only fails on deploy.
            $table->index(
                ['recipient_type', 'recipient_id'],
                'voight_alert_recipients_recipient_index'
            );
            $table->unique(
                ['alert_setting_id', 'recipient_type', 'recipient_id'],
                'voight_alert_recipients_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_alert_recipients');
    }
};
