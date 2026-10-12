<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_shares', function (Blueprint $table): void {
            $table->string('decision')->nullable()->after('revoked_at');
            $table->text('decision_note')->nullable()->after('decision');
            $table->string('reviewer_name')->nullable()->after('decision_note');
            $table->timestamp('decided_at')->nullable()->after('reviewer_name');
        });
    }

    public function down(): void
    {
        Schema::table('post_shares', function (Blueprint $table): void {
            $table->dropColumn(['decision', 'decision_note', 'reviewer_name', 'decided_at']);
        });
    }
};
