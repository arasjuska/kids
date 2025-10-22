<?php

use App\Enums\AddressTypeEnum;
use App\Enums\AccuracyLevelEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            $table->text('formatted_address');
            $table->string('short_address_line')->nullable();
            $table->string('street_name')->nullable();
            $table->string('street_number')->nullable();
            $table->string('city')->nullable()->index('addresses_city_index');
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Lietuva');
            $table->string('country_code', 2)->default('LT');

            $table->decimal('latitude', 11, 8)->index();
            $table->decimal('longitude', 11, 8)->index();

            $table->enum('address_type', array_map(fn ($case) => $case->value, AddressTypeEnum::cases()))
                ->default(AddressTypeEnum::UNVERIFIED->value)
                ->index();
            $table->decimal('confidence_score', 3, 2)->default(0.00);
            $table->string('geocoding_provider', 32)->nullable();
            $table->enum('accuracy_level', array_map(fn ($case) => $case->value, AccuracyLevelEnum::cases()))
                ->default(AccuracyLevelEnum::UNKNOWN->value);
            $table->unsignedTinyInteger('quality_tier')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('fields_refreshed_at')->nullable();
            $table->boolean('manually_overridden')->default(false)->index();
            $table->boolean('source_locked')->default(false)->index();
            $table->text('description')->nullable();
            $table->json('raw_api_response')->nullable();
            $table->boolean('is_virtual')->default(false)->index();
            $table->string('provider', 32)->nullable();
            $table->string('provider_place_id', 128)->nullable();
            $table->string('osm_type', 32)->nullable();
            $table->unsignedBigInteger('osm_id')->nullable();
            $table->binary('address_signature')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_place_id'], 'addresses_provider_place_id_unique');
            $table->unique(['osm_type', 'osm_id'], 'addresses_osm_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE addresses
            ADD COLUMN location POINT GENERATED ALWAYS AS (ST_SRID(POINT(longitude, latitude), 4326)) STORED NOT NULL
            AFTER longitude
        SQL);

        Schema::table('addresses', function (Blueprint $table): void {
            $table->spatialIndex('location', 'sx_addresses_location');
        });

        DB::statement('ALTER TABLE addresses MODIFY address_signature BINARY(32) NULL');

        Schema::table('addresses', function (Blueprint $table): void {
            $table->unique('address_signature', 'addresses_signature_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
