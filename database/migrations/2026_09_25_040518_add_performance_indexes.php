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
        // 1. Invoices
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['status', 'due_date'], 'idx_invoices_status_due_date');
            $table->index(['student_id', 'status'], 'idx_invoices_student_status');
        });

        // 2. Attendances
        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['student_id', 'status'], 'idx_attendances_student_status');
            $table->index(['class_id', 'attended_at'], 'idx_attendances_class_attended');
        });

        // 3. Students
        Schema::table('students', function (Blueprint $table) {
            $table->index(['school_id', 'status'], 'idx_students_school_status');
            $table->index(['status', 'is_verified'], 'idx_students_status_verified');
        });

        // 4. Payments
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'idx_payments_status_created');
        });

        // 5. Schools
        Schema::table('schools', function (Blueprint $table) {
            $table->index(['pipeline_status', 'updated_at'], 'idx_schools_pipeline_updated');
            $table->index('updated_at', 'idx_schools_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropIndex('idx_schools_pipeline_updated');
            $table->dropIndex('idx_schools_updated_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_status_created');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('idx_students_school_status');
            $table->dropIndex('idx_students_status_verified');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('idx_attendances_student_status');
            $table->dropIndex('idx_attendances_class_attended');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_status_due_date');
            $table->dropIndex('idx_invoices_student_status');
        });
    }
};
