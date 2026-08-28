<?php
// sentinel-back/database/migrations/2026_08_27_100000_create_device_pairings_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_pairings', function (Blueprint $table) {
            $table->id();
            $table->string('device_token_hash')->unique();
            $table->string('mode', 10); // self | kiosk
            $table->foreignId('paired_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('paired_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairings');
    }
};
