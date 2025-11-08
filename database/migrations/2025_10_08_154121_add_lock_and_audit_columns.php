<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('addresses', 'manually_overridden')) {
            Schema::table('addresses', function (Blueprint $table): void {
                $table->boolean('manually_overridden')
                    ->default(false)
                    ->index()
                    ->after('fields_refreshed_at');
            });
        }

        if (! Schema::hasColumn('addresses', 'requires_verification')) {
            Schema::table('addresses', function (Blueprint $table): void {
                $table->boolean('requires_verification')
                    ->default(false)
                    ->index()
                    ->after('manually_overridden');
            });
        }

        if (! Schema::hasColumn('addresses', 'override_reason')) {
            Schema::table('addresses', function (Blueprint $table): void {
                $table->string('override_reason', 500)
                    ->nullable()
                    ->after('requires_verification');
            });
        }

        if (! Schema::hasColumn('addresses', 'verified_at')) {
            Schema::table('addresses', function (Blueprint $table): void {
                $table->timestamp('verified_at')
                    ->nullable()
                    ->after('quality_tier');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('addresses', 'override_reason')) {
            Schema::table('addresses', function (Blueprint $table): void {
                $table->dropColumn('override_reason');
            });
        }
    }
};
