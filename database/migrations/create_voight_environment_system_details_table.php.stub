<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_environment_system_details', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('environment_id')->unique()->constrained('voight_environments')->cascadeOnDelete();
            $table->string('php_version')->nullable()->index();
            $table->string('laravel_version')->nullable()->index();
            $table->string('filament_version')->nullable()->index();
            $table->string('livewire_version')->nullable()->index();
            $table->json('payload');
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_environment_system_details');
    }
};
