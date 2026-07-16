<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

// ================================================================
// User
// ================================================================
class User extends Authenticatable implements JWTSubject {
    use Notifiable, SoftDeletes;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id','tenant_id','name','email','password','role','institution','two_factor_enabled','two_factor_secret','two_factor_backup_codes','is_active','timezone','locale'];
    protected $hidden = ['password','remember_token','two_factor_secret','two_factor_backup_codes'];
    protected $casts = ['email_verified_at'=>'datetime','two_factor_enabled'=>'boolean','two_factor_backup_codes'=>'array','is_active'=>'boolean'];
    protected $attributes = ['is_active'=>true,'timezone'=>'UTC','locale'=>'en','role'=>'student'];

    public function getJWTIdentifier(): mixed { return $this->getKey(); }
    public function getJWTCustomClaims(): array { return ['role'=>$this->role]; }

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function exams() { return $this->hasMany(Exam::class,'created_by'); }
    public function sessions() { return $this->hasMany(ExamSession::class); }
    public function badges() { return $this->hasMany(Badge::class); }
    public function certificates() { return $this->hasMany(Certificate::class); }
    public function notifications() { return $this->hasMany(Notification::class); }
    public function enrollments() { return $this->hasMany(CourseEnrollment::class); }

    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isInstructor(): bool { return $this->role === 'instructor' || $this->role === 'admin'; }
    public function isStudent(): bool { return $this->role === 'student'; }

    public function toPublicArray(): array {
        return ['id'=>$this->id,'name'=>$this->name,'email'=>$this->email,'role'=>$this->role,'institution'=>$this->institution,'timezone'=>$this->timezone,'locale'=>$this->locale,'is_active'=>$this->is_active,'two_factor_enabled'=>$this->two_factor_enabled,'created_at'=>$this->created_at];
    }
}

// ================================================================
// Tenant
// ================================================================
class Tenant extends Model {
    use SoftDeletes;
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','name','slug','admin_email','plan','primary_color','logo_url','is_active','settings'];
    protected $casts = ['is_active'=>'boolean','settings'=>'array'];
    protected $attributes = ['plan'=>'starter','primary_color'=>'#1a7f5a','is_active'=>true];
    public function users() { return $this->hasMany(User::class); }
    public function exams() { return $this->hasMany(Exam::class); }
}

// ================================================================
// Course
// ================================================================
class Course extends Model {
    use SoftDeletes;
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','tenant_id','created_by','code','name','description','join_code','is_active'];
    protected $casts = ['is_active'=>'boolean'];
    protected $attributes = ['is_active'=>true];
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
    public function enrollments() { return $this->hasMany(CourseEnrollment::class); }
    public function students() { return $this->hasManyThrough(User::class, CourseEnrollment::class, 'course_id','id','id','user_id'); }
    public function exams() { return $this->hasMany(Exam::class); }
}

// ================================================================
// Exam
// ================================================================
class Exam extends Model {
    use SoftDeletes;
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','tenant_id','created_by','course_id','title','description','duration','pass_mark','proctoring_level','status','late_policy','grace_period','late_penalty','max_attempts','attempt_cooldown','score_display','shuffle_questions','shuffle_options','adaptive_mode','adaptive_config','window_start','window_end','timezone','results_published','results_visibility','total_submissions'];
    protected $casts = ['shuffle_questions'=>'boolean','shuffle_options'=>'boolean','adaptive_mode'=>'boolean','results_published'=>'boolean','adaptive_config'=>'array','results_visibility'=>'array','window_start'=>'datetime','window_end'=>'datetime'];
    protected $attributes = ['status'=>'draft','proctoring_level'=>'basic','shuffle_questions'=>false,'shuffle_options'=>false,'max_attempts'=>1,'attempt_cooldown'=>0,'grace_period'=>0,'late_policy'=>'block','score_display'=>'best','adaptive_mode'=>false,'results_published'=>false,'total_submissions'=>0,'timezone'=>'UTC'];

    public function creator() { return $this->belongsTo(User::class,'created_by'); }
    public function course() { return $this->belongsTo(Course::class); }
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function examQuestions() { return $this->hasMany(ExamQuestion::class)->orderBy('order'); }
    public function questions() { return $this->hasManyThrough(Question::class, ExamQuestion::class, 'exam_id','id','id','question_id'); }
    public function sessions() { return $this->hasMany(ExamSession::class); }
    public function pool() { return $this->hasOne(QuestionPool::class); }
    public function certificates() { return $this->hasMany(Certificate::class); }

    public function isAvailable(): bool { return $this->status === 'published' && $this->isInWindow(); }
    public function isInWindow(): bool {
        $now = now();
        return (!$this->window_start || $now->gte($this->window_start)) && (!$this->window_end || $now->lte($this->window_end->addMinutes($this->grace_period??0)));
    }
}

// ================================================================
// Question
// ================================================================
class Question extends Model {
    use SoftDeletes;
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','created_by','tenant_id','type','text','options','answer','explanation','points','difficulty','tags','language','used_count','avg_score','version_history','version','rubric'];
    protected $casts = ['options'=>'array','tags'=>'array','version_history'=>'array','rubric'=>'array'];
    protected $attributes = ['points'=>5,'difficulty'=>'medium','language'=>'en','used_count'=>0,'version'=>1];

    public function creator() { return $this->belongsTo(User::class,'created_by'); }
    public function examItems() { return $this->hasMany(ExamQuestion::class); }
    public function gradingResults() { return $this->hasMany(AiGradingResult::class); }

    protected static function booted(): void {
        static::updating(function (Question $q) {
            if ($q->isDirty('text')) {
                $history = $q->version_history ?? [];
                $history[] = ['version'=>$q->version,'text'=>$q->getOriginal('text'),'changed_by'=>auth()->id(),'changed_at'=>now()->toIso8601String()];
                $q->version_history = $history;
                $q->version += 1;
            }
        });
    }
}

// ================================================================
// ExamQuestion (pivot)
// ================================================================
class ExamQuestion extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','exam_id','question_id','order','points_override'];
    public function exam() { return $this->belongsTo(Exam::class); }
    public function question() { return $this->belongsTo(Question::class); }
}

// ================================================================
// ExamSession
// ================================================================
class ExamSession extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','exam_id','user_id','attempt_number','time_left','current_question','answers','flagged_questions','question_order','status','grade_status','auto_score','essay_score','final_score','total_possible','passed','last_saved_at','submitted_at','tab_switches','copy_attempts','ip_addresses'];
    protected $casts = ['answers'=>'array','flagged_questions'=>'array','question_order'=>'array','ip_addresses'=>'array','passed'=>'boolean','submitted_at'=>'datetime','last_saved_at'=>'datetime'];
    protected $attributes = ['attempt_number'=>1,'current_question'=>0,'status'=>'in_progress','grade_status'=>'pending','tab_switches'=>0,'copy_attempts'=>0];

    public function exam() { return $this->belongsTo(Exam::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function proctoringLogs() { return $this->hasMany(ProctoringLog::class,'session_id'); }
    public function aiGradingResults() { return $this->hasMany(AiGradingResult::class,'session_id'); }
    public function certificate() { return $this->hasOne(Certificate::class,'session_id'); }

    public function getRiskScore(): int {
        $viols = $this->proctoringLogs()->count();
        $tabs = $this->tab_switches ?? 0;
        return min(100, $viols * 15 + $tabs * 10);
    }
}

// ================================================================
// ProctoringLog
// ================================================================
class ProctoringLog extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','session_id','user_id','exam_id','violation_type','severity','description','screenshot_url','metadata','risk_score_delta'];
    protected $casts = ['metadata'=>'array'];
    public function session() { return $this->belongsTo(ExamSession::class,'session_id'); }
    public function user() { return $this->belongsTo(User::class); }
}

// ================================================================
// AiGradingResult
// ================================================================
class AiGradingResult extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','session_id','question_id','graded_by','student_answer','model_answer','similarity_score','ai_score','awarded_points','max_points','grade','confidence','rubric_breakdown','keywords','feedback','plagiarism_detected','ai_generated_detected','status','instructor_override_points','instructor_notes','ai_graded_at','approved_at'];
    protected $casts = ['rubric_breakdown'=>'array','keywords'=>'array','plagiarism_detected'=>'boolean','ai_generated_detected'=>'boolean','ai_graded_at'=>'datetime','approved_at'=>'datetime'];
    protected $attributes = ['status'=>'pending'];
    public function session() { return $this->belongsTo(ExamSession::class,'session_id'); }
    public function question() { return $this->belongsTo(Question::class); }
    public function grader() { return $this->belongsTo(User::class,'graded_by'); }
}

// ================================================================
// Certificate
// ================================================================
class Certificate extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','user_id','exam_id','session_id','type','institution','hash','prev_hash','block_number','final_score','issued_at'];
    protected $casts = ['issued_at'=>'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function exam() { return $this->belongsTo(Exam::class); }
    public function session() { return $this->belongsTo(ExamSession::class,'session_id'); }
}

// ================================================================
// Badge
// ================================================================
class Badge extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','user_id','awarded_by','type','reason','metadata'];
    protected $casts = ['metadata'=>'array'];
    public function user() { return $this->belongsTo(User::class); }
    public function awardedBy() { return $this->belongsTo(User::class,'awarded_by'); }
}

// ================================================================
// GradeAppeal
// ================================================================
class GradeAppeal extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','session_id','user_id','reviewed_by','reason','statement','status','original_score','revised_score','reviewer_notes','ai_recommendation','reviewed_at'];
    protected $casts = ['reviewed_at'=>'datetime'];
    protected $attributes = ['status'=>'open'];
    public function session() { return $this->belongsTo(ExamSession::class,'session_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function reviewer() { return $this->belongsTo(User::class,'reviewed_by'); }
}

// ================================================================
// Notification
// ================================================================
class Notification extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','user_id','recipient_type','title','body','type','read','data'];
    protected $casts = ['read'=>'boolean','data'=>'array'];
    protected $attributes = ['recipient_type'=>'user','type'=>'info','read'=>false];
    public function user() { return $this->belongsTo(User::class); }
}

// ================================================================
// AuditLog
// ================================================================
class AuditLog extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','user_id','category','action','detail','severity','ip_address','user_agent','changes'];
    protected $casts = ['changes'=>'array'];
    public $timestamps = true; const UPDATED_AT = null;
    protected $attributes = ['severity'=>'info'];
    public function user() { return $this->belongsTo(User::class); }
}

// ================================================================
// Webhook + WebhookDelivery
// ================================================================
class Webhook extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','tenant_id','url','secret','events','is_active','deliveries','failures'];
    protected $casts = ['events'=>'array','is_active'=>'boolean'];
    protected $attributes = ['is_active'=>true,'deliveries'=>0,'failures'=>0];
    public function deliveries_rel() { return $this->hasMany(WebhookDelivery::class); }
    public function deliveries() { return $this->hasMany(WebhookDelivery::class); }
}

class WebhookDelivery extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','webhook_id','event','payload','response_status','response_body','duration_ms'];
    protected $casts = ['payload'=>'array'];
    public function webhook() { return $this->belongsTo(Webhook::class); }
}

// ================================================================
// QuestionPool
// ================================================================
class QuestionPool extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','exam_id','name','pool_size','draw_count','difficulty_mix'];
    protected $casts = ['difficulty_mix'=>'array'];
    public function exam() { return $this->belongsTo(Exam::class); }
}

// ================================================================
// PeerReview
// ================================================================
class PeerReview extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','exam_id','reviewer_id','reviewee_id','status','score','feedback','deadline','completed_at'];
    protected $casts = ['deadline'=>'datetime','completed_at'=>'datetime'];
    protected $attributes = ['status'=>'pending'];
    public function exam() { return $this->belongsTo(Exam::class); }
    public function reviewer() { return $this->belongsTo(User::class,'reviewer_id'); }
    public function reviewee() { return $this->belongsTo(User::class,'reviewee_id'); }
}

// ================================================================
// CourseEnrollment
// ================================================================
class CourseEnrollment extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','course_id','user_id'];
    public function course() { return $this->belongsTo(Course::class); }
    public function user() { return $this->belongsTo(User::class); }
}

// ================================================================
// Rubric
// ================================================================
class Rubric extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','created_by','name','criteria','total_points'];
    protected $casts = ['criteria'=>'array'];
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
}

// ================================================================
// VoiceSubmission
// ================================================================
class VoiceSubmission extends Model {
    protected $keyType = 'string'; public $incrementing = false;
    protected $fillable = ['id','session_id','question_id','audio_url','transcript','language','duration_seconds'];
    public function session() { return $this->belongsTo(ExamSession::class,'session_id'); }
    public function question() { return $this->belongsTo(Question::class); }
}
