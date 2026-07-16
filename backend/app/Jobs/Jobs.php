<?php
namespace App\Jobs;

use App\Models\{Exam, ExamSession, AiGradingResult, Badge, Webhook, WebhookDelivery};
use App\Services\AiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\{Str, Facades\Http, Facades\Log, Facades\DB};

// ================================================================
// GradeEssaysJob
// ================================================================
class GradeEssaysJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(private ExamSession $session, private Exam $exam) {
        $this->queue = 'grading';
    }

    public function handle(AiService $ai): void {
        $questions = $this->exam->examQuestions()->with('question')->get()->keyBy('question_id');
        $answers = $this->session->answers ?? [];
        $allGraded = true;

        foreach ($this->session->question_order ?? [] as $qId) {
            $eq = $questions->get($qId);
            if (!$eq) continue;
            $q = $eq->question;
            $points = $eq->points_override ?? $q->points;
            $answer = $answers[$qId] ?? null;
            if (!in_array($q->type, ['essay','coding'])) continue;
            if (empty($answer)) continue;

            $existing = AiGradingResult::where('session_id',$this->session->id)->where('question_id',$qId)->where('status','!=','pending')->first();
            if ($existing) continue;

            try {
                $result = match($q->type) {
                    'essay'  => $ai->gradeEssay($q->text, $q->answer??'', $answer, $points),
                    'coding' => $ai->evaluateCode($q->text, $answer, $q->answer??'', 'auto-detect', $points),
                };

                AiGradingResult::updateOrCreate(
                    ['session_id'=>$this->session->id,'question_id'=>$qId],
                    ['id'=>Str::uuid(),'student_answer'=>$answer,'model_answer'=>$q->answer,'similarity_score'=>$result['similarity']??null,'ai_score'=>$result['score']??0,'awarded_points'=>$result['awarded']??0,'max_points'=>$points,'grade'=>$result['grade']??'F','confidence'=>$result['confidence']??75,'rubric_breakdown'=>$result['rubric']??[],'keywords'=>$result['keywords']??[],'feedback'=>$result['feedback']??'','plagiarism_detected'=>$result['plagiarism']??false,'ai_generated_detected'=>$result['ai_generated']??false,'status'=>'ai_graded','ai_graded_at'=>now()]
                );

                $threshold = config('examedge.grading.auto_approve_confidence', 92);
                if (($result['confidence']??0) >= $threshold) {
                    AiGradingResult::where('session_id',$this->session->id)->where('question_id',$qId)->update(['status'=>'instructor_approved','approved_at'=>now()]);
                } else {
                    $allGraded = false;
                }
            } catch (\Exception $e) {
                Log::error('Essay grading failed', ['session_id'=>$this->session->id,'question_id'=>$qId,'error'=>$e->getMessage()]);
                $allGraded = false;
            }
        }

        if ($allGraded) $this->finalizeSession();
    }

    private function finalizeSession(): void {
        $results = AiGradingResult::where('session_id',$this->session->id)->get();
        $essayScore = $results->sum('awarded_points');
        $total = ($this->session->auto_score??0) + $essayScore;
        $totalPossible = $this->session->total_possible ?? 1;
        $pct = round($total / $totalPossible * 100);

        $this->session->update(['essay_score'=>$essayScore,'final_score'=>$pct,'passed'=>$pct >= $this->exam->pass_mark,'grade_status'=>'graded']);
        AwardBadgesJob::dispatch($this->session->user_id, $this->exam->id, $this->session->id);
    }

    public function failed(\Throwable $e): void {
        Log::error('GradeEssaysJob failed permanently', ['session'=>$this->session->id,'error'=>$e->getMessage()]);
    }
}

// ================================================================
// WebhookDeliveryJob
// ================================================================
class WebhookDeliveryJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 4;
    public int $timeout = 30;

    public function __construct(private Webhook $webhook, private string $event, private array $payload) {
        $this->queue = 'webhooks';
    }

    public function handle(): void {
        $body = json_encode(['event'=>$this->event,'timestamp'=>now()->toIso8601String(),'data'=>$this->payload]);
        $signature = hash_hmac('sha256', $body, $this->webhook->secret);
        $start = microtime(true);

        try {
            $response = Http::withHeaders([
                'Content-Type'=>'application/json',
                'X-ExamEdge-Event'=>$this->event,
                'X-ExamEdge-Signature'=>"sha256={$signature}",
                'X-ExamEdge-Delivery'=>Str::uuid(),
            ])->timeout(25)->post($this->webhook->url, json_decode($body,true));

            $duration = intval((microtime(true)-$start)*1000);

            WebhookDelivery::create(['id'=>Str::uuid(),'webhook_id'=>$this->webhook->id,'event'=>$this->event,'payload'=>json_decode($body,true),'response_status'=>$response->status(),'response_body'=>substr($response->body(),0,1000),'duration_ms'=>$duration]);

            if ($response->successful()) $this->webhook->increment('deliveries');
            else { $this->webhook->increment('failures'); $this->fail(new \Exception("HTTP {$response->status()}")); }

        } catch (\Exception $e) {
            $this->webhook->increment('failures');
            WebhookDelivery::create(['id'=>Str::uuid(),'webhook_id'=>$this->webhook->id,'event'=>$this->event,'payload'=>json_decode($body,true),'response_status'=>0,'response_body'=>$e->getMessage(),'duration_ms'=>intval((microtime(true)-$start)*1000)]);
            throw $e;
        }
    }

    public function backoff(): array { return [30, 60, 300]; }
}

// ================================================================
// AwardBadgesJob
// ================================================================
class AwardBadgesJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(private string $userId, private string $examId, private string $sessionId) {
        $this->queue = 'default';
    }

    public function handle(): void {
        $session = ExamSession::with(['exam','user'])->find($this->sessionId);
        if (!$session || !$session->final_score) return;
        $score = $session->final_score;

        if ($score >= 100) $this->award($this->userId, 'perfect', 'Achieved 100% on '.$session->exam->title);

        $attempts = ExamSession::where('exam_id',$this->examId)->where('user_id',$this->userId)->where('status','submitted')->count();
        if ($attempts === 1 && $session->passed) $this->award($this->userId, 'first_pass', 'Passed on first attempt: '.$session->exam->title);

        $maxScore = ExamSession::where('exam_id',$this->examId)->where('status','submitted')->max('final_score');
        if ($score >= $maxScore && $score > 0) $this->award($this->userId, 'top_scorer', "Top scorer ({$score}%) on ".$session->exam->title);

        $recentPasses = ExamSession::where('user_id',$this->userId)->where('status','submitted')->where('passed',true)->latest('submitted_at')->take(3)->count();
        if ($recentPasses >= 3) $this->award($this->userId, 'streak_3', '3 consecutive passed exams');

        $submitted = ExamSession::where('user_id',$this->userId)->where('status','submitted')->count();
        if ($submitted >= 5) {
            $violations = DB::table('proctoring_logs')->where('user_id',$this->userId)->count();
            if ($violations === 0) $this->award($this->userId, 'integrity', '5+ exams with zero violations');
        }
    }

    private function award(string $userId, string $type, string $reason): void {
        $exists = Badge::where('user_id',$userId)->where('type',$type)->whereDate('created_at',today())->exists();
        if (!$exists) Badge::create(['id'=>Str::uuid(),'user_id'=>$userId,'type'=>$type,'reason'=>$reason]);
    }
}

// ================================================================
// SendExamNotificationJob
// ================================================================
class SendExamNotificationJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(private Exam $exam, private string $event) {
        $this->queue = 'notifications';
    }

    public function handle(): void {
        $messages = [
            'published'=>['title'=>'New exam available','body'=>"{$this->exam->title} is now available. Good luck!"],
            'scheduled'=>['title'=>'Exam scheduled','body'=>"{$this->exam->title} starts at ".($this->exam->window_start?->format('d M Y H:i')??'TBD')],
            'results_published'=>['title'=>'Results available','body'=>"Your results for {$this->exam->title} are now published."],
        ];
        $msg = $messages[$this->event] ?? null;
        if (!$msg) return;

        $students = $this->exam->course ? $this->exam->course->students()->pluck('users.id') : collect();
        if ($students->isEmpty()) return;

        $rows = $students->map(fn($uid)=>['id'=>Str::uuid(),'user_id'=>$uid,'recipient_type'=>'user','title'=>$msg['title'],'body'=>$msg['body'],'type'=>'info','read'=>false,'created_at'=>now(),'updated_at'=>now()])->toArray();
        DB::table('notifications')->insert($rows);
    }
}
