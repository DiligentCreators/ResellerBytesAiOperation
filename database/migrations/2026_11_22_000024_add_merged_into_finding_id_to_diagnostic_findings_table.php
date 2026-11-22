<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_findings', function (Blueprint $table): void {
            // No foreign key: avoid RLS / composite-FK complexity on tenant-scoped findings.
            $table->uuid('merged_into_finding_id')->nullable()->after('status_note');
            $table->index('merged_into_finding_id', 'diagnostic_findings_merged_into_finding_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_findings', function (Blueprint $table): void {
            $table->dropIndex('diagnostic_findings_merged_into_finding_id_index');
            $table->dropColumn('merged_into_finding_id');
        });
    }
};
