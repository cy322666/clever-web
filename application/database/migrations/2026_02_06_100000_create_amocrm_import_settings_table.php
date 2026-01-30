<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->boolean('active')->default(false);

            // Настройки по умолчанию
            $table->integer('default_status_id')->nullable();
            $table->integer('default_pipeline_id')->nullable();
            $table->integer('default_responsible_user_id')->nullable();
            $table->string('default_lead_name')->nullable();

            // Маппинг полей: JSON с массивом {column: "A", entity: "lead|contact|company", field: "field_id"}
            $table->json('fields_mapping')->nullable();

            // Поведение при дублях
            $table->boolean('check_duplicates')->default(true);
            $table->boolean('update_existing_contacts')->default(true);
            $table->boolean('update_existing_leads')->default(false);
            $table->boolean('link_contact_to_company')->default(true);

            // Тег для импортированных сущностей
            $table->string('tag')->nullable();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_settings');
    }
};
