<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_code')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('repo_url')->nullable();
            $table->foreignUlid('customer_id')->nullable()->constrained('voight_customers')->nullOnDelete();
            $table->foreignUlid('team_id')->nullable()->constrained('voight_teams')->nullOnDelete();
            $table->boolean('is_muted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_projects');
    }
};
