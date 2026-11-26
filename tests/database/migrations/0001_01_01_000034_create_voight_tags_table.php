<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->json('name');
            $table->json('slug');
            $table->string('type');
            $table->integer('order_column')->nullable();
            $table->timestamps();

            $table->foreign('type')->references('key')->on('voight_tag_types')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_tags');
    }
};
