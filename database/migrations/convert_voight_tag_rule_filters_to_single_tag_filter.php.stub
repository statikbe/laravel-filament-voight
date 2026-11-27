<?php

use Illuminate\Database\Migrations\Migration;
use Statikbe\FilamentVoight\Services\TagRuleFilterConverter;

return new class extends Migration
{
    public function up(): void
    {
        app(TagRuleFilterConverter::class)->convertAll();
    }

    // The old per-type filters cannot be rebuilt from the single filter, and the stored query of a rule does not depend on them.
    public function down(): void {}
};
