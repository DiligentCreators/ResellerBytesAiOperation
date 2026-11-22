<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_findings', function (Blueprint $table): void {
            $table->string('issue_key', 64)->nullable()->after('fingerprint');
            $table->string('source', 16)->default('ai')->after('issue_key');
            $table->unsignedInteger('affected_count')->nullable()->after('source');
            $table->string('fix_mode', 16)->nullable()->after('affected_count');
            $table->timestamp('ignored_until')->nullable()->after('fix_mode');

            $table->index('issue_key', 'diagnostic_findings_issue_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_findings', function (Blueprint $table): void {
            $table->dropIndex('diagnostic_findings_issue_key_index');
            $table->dropColumn([
                'issue_key',
                'source',
                'affected_count',
                'fix_mode',
                'ignored_until',
            ]);
        });
    }
};
