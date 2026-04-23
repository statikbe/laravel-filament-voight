<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_vulnerabilities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('source');
            $table->string('source_id');
            $table->json('aliases')->nullable();
            $table->string('summary');
            $table->text('details')->nullable();
            $table->decimal('vulnerability_score', 3, 1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_vulnerabilities');
    }
};
