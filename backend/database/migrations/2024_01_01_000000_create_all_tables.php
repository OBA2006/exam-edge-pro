<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Tenants
        Schema::create('tenants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name'); $t->string('slug')->unique(); $t->string('admin_email');
            $t->enum('plan',['starter','growth','enterprise'])->default('starter');
            $t->string('primary_color')->default('#1a7f5a');
            $t->string('logo_url')->nullable(); $t->boolean('is_active')->default(true);
            $t->json('settings')->nullable(); $t->timestamps(); $t->softDeletes();
        });

        // Users
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name'); $t->string('email')->unique();
            $t->string('password'); $t->enum('role',['student','instructor','admin'])->default('student');
            $t->string('institution')->nullable(); $t->string('timezone')->default('UTC');
            $t->string('locale')->default('en'); $t->boolean('is_active')->default(true);
            $t->boolean('two_factor_enabled')->default(false);
            $t->string('two_factor_secret')->nullable();
            $t->json('two_factor_backup_codes')->nullable();
            $t->string('profile_photo')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->rememberToken(); $t->timestamps(); $t->softDeletes();
        });

        // Courses
        Schema::create('courses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->uuid('created_by')->constrained('users');
            $t->string('code')->unique(); $t->string('name');
            $t->text('description')->nullable();
            $t->string('join_code',10)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->softDeletes();
        });

        // Course enrollments
        Schema::create('course_enrollments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('course_id')->constrained()->cascadeOnDelete();
            $t->uuid('user_id')->constrained()->cascadeOnDelete();
            $t->unique(['course_id','user_id']); $t->timestamps();
        });

        // Exams
        Schema::create('exams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->uuid('created_by')->constrained('users');
            $t->uuid('course_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title'); $t->text('description')->nullable();
            $t->integer('duration')->default(60);
            $t->integer('pass_mark')->default(50);
            $t->enum('proctoring_level',['none','basic','full'])->default('basic');
            $t->enum('status',['draft','review','published','active','closed','grading','results','archived'])->default('draft');
            $t->enum('late_policy',['block','penalty','allow'])->default('block');
            $t->integer('grace_period')->default(0);
            $t->decimal('late_penalty',5,2)->default(0);
            $t->integer('max_attempts')->default(1);
            $t->integer('attempt_cooldown')->default(0);
            $t->enum('score_display',['best','latest','all','none'])->default('best');
            $t->boolean('shuffle_questions')->default(false);
            $t->boolean('shuffle_options')->default(false);
            $t->boolean('adaptive_mode')->default(false);
            $t->json('adaptive_config')->nullable();
            $t->timestamp('window_start')->nullable();
            $t->timestamp('window_end')->nullable();
            $t->string('timezone')->default('UTC');
            $t->boolean('results_published')->default(false);
            $t->json('results_visibility')->nullable();
            $t->integer('total_submissions')->default(0);
            $t->timestamps(); $t->softDeletes();
            $t->index(['status','window_start','window_end']);
            $t->index('created_by'); $t->index('course_id');
        });

        // Questions
        Schema::create('questions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('created_by')->constrained('users');
            $t->uuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->enum('type',['mcq','essay','coding','truefalse','fillin','multimedia']);
            $t->text('text');
            $t->json('options')->nullable();
            $t->text('answer')->nullable();
            $t->text('explanation')->nullable();
            $t->integer('points')->default(5);
            $t->enum('difficulty',['easy','medium','hard'])->default('medium');
            $t->json('tags')->nullable();
            $t->string('language',5)->default('en');
            $t->integer('used_count')->default(0);
            $t->decimal('avg_score',5,2)->nullable();
            $t->json('version_history')->nullable();
            $t->integer('version')->default(1);
            $t->json('rubric')->nullable();
            $t->timestamps(); $t->softDeletes();
            $t->index(['type','difficulty']); $t->index('created_by');
        });

        // Exam questions (pivot)
        Schema::create('exam_questions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_id')->constrained()->cascadeOnDelete();
            $t->uuid('question_id')->constrained()->cascadeOnDelete();
            $t->integer('order')->default(0);
            $t->integer('points_override')->nullable();
            $t->timestamps();
            $t->unique(['exam_id','question_id']);
            $t->index(['exam_id','order']);
        });

        // Exam sessions
        Schema::create('exam_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_id')->constrained()->cascadeOnDelete();
            $t->uuid('user_id')->constrained()->cascadeOnDelete();
            $t->integer('attempt_number')->default(1);
            $t->integer('time_left')->default(0);
            $t->integer('current_question')->default(0);
            $t->json('answers')->nullable();
            $t->json('flagged_questions')->nullable();
            $t->json('question_order')->nullable();
            $t->enum('status',['in_progress','submitted','abandoned'])->default('in_progress');
            $t->enum('grade_status',['pending','pending_ai','graded'])->default('pending');
            $t->decimal('auto_score',8,2)->nullable();
            $t->decimal('essay_score',8,2)->nullable();
            $t->decimal('final_score',5,2)->nullable();
            $t->decimal('total_possible',8,2)->nullable();
            $t->boolean('passed')->nullable();
            $t->timestamp('last_saved_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->integer('tab_switches')->default(0);
            $t->integer('copy_attempts')->default(0);
            $t->json('ip_addresses')->nullable();
            $t->timestamps();
            $t->index(['exam_id','user_id','status']);
            $t->index(['grade_status','status']);
        });

        // Proctoring logs
        Schema::create('proctoring_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $t->uuid('user_id')->constrained('users');
            $t->uuid('exam_id')->constrained('exams');
            $t->enum('violation_type',['tab_switch','face_absent','multiple_faces','phone_detected','copy_attempt','keyboard_shortcut','right_click','fullscreen_exit','screen_share']);
            $t->enum('severity',['low','medium','high']);
            $t->text('description')->nullable();
            $t->string('screenshot_url')->nullable();
            $t->json('metadata')->nullable();
            $t->integer('risk_score_delta')->default(10);
            $t->timestamps();
            $t->index(['session_id','violation_type']);
            $t->index(['exam_id','severity']);
        });

        // AI grading results
        Schema::create('ai_grading_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $t->uuid('question_id')->constrained('questions');
            $t->uuid('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('student_answer')->nullable();
            $t->text('model_answer')->nullable();
            $t->decimal('similarity_score',5,2)->nullable();
            $t->decimal('ai_score',5,2)->nullable();
            $t->decimal('awarded_points',8,2)->nullable();
            $t->decimal('max_points',8,2)->default(0);
            $t->string('grade',3)->nullable();
            $t->integer('confidence')->nullable();
            $t->json('rubric_breakdown')->nullable();
            $t->json('keywords')->nullable();
            $t->text('feedback')->nullable();
            $t->boolean('plagiarism_detected')->default(false);
            $t->boolean('ai_generated_detected')->default(false);
            $t->enum('status',['pending','ai_graded','instructor_approved','instructor_overridden'])->default('pending');
            $t->decimal('instructor_override_points',8,2)->nullable();
            $t->text('instructor_notes')->nullable();
            $t->timestamp('ai_graded_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['session_id','question_id']);
            $t->index(['status','session_id']);
        });

        // Rubrics
        Schema::create('rubrics', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('created_by')->constrained('users');
            $t->string('name'); $t->json('criteria'); $t->integer('total_points');
            $t->timestamps();
        });

        // Grade appeals
        Schema::create('grade_appeals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $t->uuid('user_id')->constrained('users');
            $t->uuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->enum('reason',['marking_error','technical','medical','ai_grading','other']);
            $t->text('statement');
            $t->enum('status',['open','under_review','upheld','modified','rejected'])->default('open');
            $t->decimal('original_score',5,2)->nullable();
            $t->decimal('revised_score',5,2)->nullable();
            $t->text('reviewer_notes')->nullable();
            $t->text('ai_recommendation')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['status','user_id']);
        });

        // Certificates
        Schema::create('certificates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->uuid('exam_id')->constrained('exams');
            $t->uuid('session_id')->constrained('exam_sessions');
            $t->enum('type',['completion','excellence','distinction','participation']);
            $t->string('institution');
            $t->string('hash',64)->unique();
            $t->string('prev_hash',64);
            $t->integer('block_number')->unsigned();
            $t->decimal('final_score',5,2)->nullable();
            $t->timestamp('issued_at');
            $t->timestamps();
            $t->index(['user_id','exam_id']);
        });

        // Badges
        Schema::create('badges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->uuid('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->enum('type',['top_scorer','perfect','most_improved','fast_finisher','streak_3','integrity','first_pass','participation']);
            $t->string('reason')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['user_id','type']);
        });

        // Notifications
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->enum('recipient_type',['user','all','students','instructors'])->default('user');
            $t->string('title'); $t->text('body');
            $t->enum('type',['info','success','warning','alert'])->default('info');
            $t->boolean('read')->default(false);
            $t->json('data')->nullable();
            $t->timestamps();
            $t->index(['user_id','read']);
        });

        // Audit logs
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('category',50); $t->string('action',100);
            $t->text('detail')->nullable();
            $t->enum('severity',['info','warning','error','critical'])->default('info');
            $t->string('ip_address',45)->nullable();
            $t->string('user_agent')->nullable();
            $t->json('changes')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['category','action']); $t->index(['user_id','created_at']);
        });

        // Webhooks
        Schema::create('webhooks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->string('url'); $t->string('secret');
            $t->json('events'); $t->boolean('is_active')->default(true);
            $t->integer('deliveries')->default(0); $t->integer('failures')->default(0);
            $t->timestamps();
        });

        // Webhook deliveries
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $t->string('event'); $t->json('payload');
            $t->integer('response_status')->nullable();
            $t->text('response_body')->nullable();
            $t->integer('duration_ms')->nullable();
            $t->timestamps();
            $t->index(['webhook_id','created_at']);
        });

        // Question pools
        Schema::create('question_pools', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_id')->constrained('exams')->cascadeOnDelete();
            $t->string('name'); $t->integer('pool_size'); $t->integer('draw_count');
            $t->json('difficulty_mix')->nullable(); $t->timestamps();
        });

        // Peer reviews
        Schema::create('peer_reviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_id')->constrained('exams')->cascadeOnDelete();
            $t->uuid('reviewer_id')->constrained('users'); $t->uuid('reviewee_id')->constrained('users');
            $t->enum('status',['pending','completed'])->default('pending');
            $t->decimal('score',5,2)->nullable(); $t->text('feedback')->nullable();
            $t->timestamp('deadline')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        // Voice submissions
        Schema::create('voice_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $t->uuid('question_id')->constrained('questions');
            $t->string('audio_url'); $t->text('transcript')->nullable();
            $t->string('language',10)->default('en'); $t->integer('duration_seconds')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('voice_submissions');
        Schema::dropIfExists('peer_reviews');
        Schema::dropIfExists('question_pools');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('grade_appeals');
        Schema::dropIfExists('rubrics');
        Schema::dropIfExists('ai_grading_results');
        Schema::dropIfExists('proctoring_logs');
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('users');
        Schema::dropIfExists('tenants');
    }
};
