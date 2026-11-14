<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('Auditor principal')->after('email');
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tax_id')->nullable();
            // Homologación de columnas: { "Encabezado del archivo": "employeeId|employee|concept|value|quantity" }
            $table->json('column_mapping')->nullable();
            $table->timestamps();
        });

        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('period_key', 7); // YYYY-MM
            $table->timestamps();
            $table->unique(['company_id', 'period_key']);
        });

        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name');
            $table->string('period_label');
            $table->json('files')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('differences')->default(0);
            $table->unsignedInteger('missing')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->timestamps();
        });

        Schema::create('audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('type', 20); // duplicate | missing_main | missing_compare | difference
            $table->string('employee')->nullable();
            $table->string('employee_id')->nullable();
            $table->string('concept')->nullable();
            $table->string('file_name')->nullable();
            $table->string('main_value')->nullable();
            $table->string('compared_value')->nullable();
            $table->decimal('difference', 18, 2)->default(0);
            $table->text('description');
            $table->text('suggestion');
            $table->string('status')->default('Revisión manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_findings');
        Schema::dropIfExists('audits');
        Schema::dropIfExists('periods');
        Schema::dropIfExists('companies');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
