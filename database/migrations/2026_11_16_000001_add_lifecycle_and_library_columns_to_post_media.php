<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->string('original_filename')->nullable()->after('path');
            $table->string('upload_status', 32)->default('available')->after('storage_error');
            $table->string('processing_status', 32)->default('ready')->after('upload_status');
            $table->foreignUuid('uploaded_by_user_id')->nullable()->after('workspace_id')->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('edit_settings');
            $table->softDeletes();

            $table->index(['workspace_id', 'archived_at']);
            $table->index(['workspace_id', 'upload_status']);
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'upload_status']);
            $table->dropIndex(['workspace_id', 'archived_at']);
            $table->dropConstrainedForeignId('uploaded_by_user_id');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'original_filename',
                'upload_status',
                'processing_status',
                'archived_at',
            ]);
        });
    }
};
