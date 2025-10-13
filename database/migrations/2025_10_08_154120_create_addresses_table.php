<?php

use App\Enums\AddressTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

            // Core address fields
            $table->text('formatted_address');
            $table->string('street_name')->nullable();
            $table->string('street_number')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Lietuva');
            $table->string('country_code', 2)->default('LT');

            // Coordinates (Patobulinta: 11, 8 Precision)
            // Tiesioginis indeksų pridėjimas leidžia geriau naudoti Bounding Box
            $table->decimal('latitude', 11, 8)->index();
            $table->decimal('longitude', 11, 8)->index();

            // Metadata
            $table->enum('address_type', array_map(fn($case) => $case->value, AddressTypeEnum::cases()))->default(AddressTypeEnum::UNVERIFIED->value)->index(); // Pridėtas indeksas prie Enum
            $table->decimal('confidence_score', 3, 2)->default(0);
            $table->text('description')->nullable(); // For virtual addresses
            $table->json('raw_api_response')->nullable();
            $table->boolean('is_virtual')->default(false)->index(); // Pridėtas indeksas prie boolean

            $table->timestamps();
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
