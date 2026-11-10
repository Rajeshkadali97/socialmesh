<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('campaign', 120)->nullable()->after('account_set_id');
            $table->index(['workspace_id', 'campaign']);
        });

        Schema::table('post_media', function (Blueprint $table): void {
            $table->string('drive_file_id')->nullable()->after('source_path');
            $table->string('checksum', 64)->nullable()->after('size_bytes');
            $table->string('campaign', 120)->nullable()->after('checksum');
            $table->text('storage_error')->nullable()->after('campaign');

            $table->index(['workspace_id', 'checksum']);
            $table->index(['workspace_id', 'drive_file_id']);
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'drive_file_id']);
            $table->dropIndex(['workspace_id', 'checksum']);
            $table->dropColumn(['drive_file_id', 'checksum', 'campaign', 'storage_error']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'campaign']);
            $table->dropColumn('campaign');
        });
    }
};
