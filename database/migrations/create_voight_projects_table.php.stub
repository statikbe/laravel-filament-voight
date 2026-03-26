<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('project_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('repo_url');
            $table->foreignUlid('customer_id')->constrained('voight_customers')->cascadeOnDelete();
            $table->foreignUlid('team_id')->constrained('voight_teams')->cascadeOnDelete();
            $table->boolean('is_muted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_projects');
    }
};
