<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_monthly_minor')->nullable()->change();
            $table->unsignedBigInteger('price_yearly_minor')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Leave columns nullable while any plan still has null prices — forcing
        // NOT NULL would fail or invent commercial amounts the platform admin owns.
        $hasNullPrices = DB::table('plans')
            ->where(function ($query): void {
                $query->whereNull('price_monthly_minor')
                    ->orWhereNull('price_yearly_minor');
            })
            ->exists();

        if ($hasNullPrices) {
            return;
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_monthly_minor')->nullable(false)->change();
            $table->unsignedBigInteger('price_yearly_minor')->nullable(false)->change();
        });
    }
};
