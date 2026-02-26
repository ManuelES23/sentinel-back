<?php

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
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'is_external',
                'owner_company',
                'contact_person',
                'contract_number',
                'contract_start_date',
                'contract_end_date',
                'contract_notes',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_external')->default(false)->after('is_main');
            $table->string('owner_company')->nullable()->after('is_external');
            $table->string('contact_person')->nullable()->after('owner_company');
            $table->string('contract_number')->nullable()->after('contact_person');
            $table->date('contract_start_date')->nullable()->after('contract_number');
            $table->date('contract_end_date')->nullable()->after('contract_start_date');
            $table->text('contract_notes')->nullable()->after('contract_end_date');
        });
    }
};
