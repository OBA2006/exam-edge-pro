<?php
namespace App\Http\Controllers;
use App\Models\{Exam, ExamSession, Question};
use App\Services\AiService;
use Illuminate\Http\{Request, JsonResponse};

class AnalyticsController extends Controller {
    public function __construct(private AiService $ai) {}

    public function overview(): JsonResponse {
        $user = auth()->user();
        $base = ExamSession::when($user->isInstructor(),fn($q)=>$q->whereHas('exam',fn($q2)=>$q2->where('created_by',$user->id)));
        $total = $base->clone()->where('status','submitted')->count();
        $passed = $base->clone()->where('passed',true)->count();
        return response()->json(['submissions'=>$total,'graded'=>$base->clone()->where('grade_status','graded')->count(),'pending_ai'=>$base->clone()->where('grade_status','pending_ai')->count(),'avg_score'=>round($base->clone()->whereNotNull('final_score')->avg('final_score')??0,1),'pass_rate'=>$total>0?round($passed/$total*100,1):0]);
    }

    public function examAnalytics(string $id): JsonResponse {
        $scores = ExamSession::where('exam_id',$id)->where('status','submitted')->whereNotNull('final_score')->pluck('final_score');
        if ($scores->isEmpty()) return response()->json(['message'=>'No data yet.']);
        $sorted=$scores->sort()->values(); $n=$sorted->count(); $mean=$sorted->avg();
        $median=$n%2===0?($sorted[$n/2-1]+$sorted[$n/2])/2:$sorted[intval($n/2)];
        $sd=sqrt($sorted->map(fn($s)=>pow($s-$mean,2))->avg());
        $dist=array_fill(0,10,0); foreach($scores as $s) $dist[min(9,intval($s/10))]++;
        return response()->json(['n'=>$n,'mean'=>round($mean,1),'median'=>round($median,1),'sd'=>round($sd,1),'min'=>$sorted->min(),'max'=>$sorted->max(),'distribution'=>$dist,'pass_rate'=>round($scores->filter(fn($s)=>$s>=Exam::find($id)->pass_mark)->count()/$n*100,1)]);
    }

    public function heatmap(string $id): JsonResponse {
        $exam = Exam::with('examQuestions.question')->findOrFail($id);
        $sessions = ExamSession::where('exam_id',$id)->where('status','submitted')->get();
        $heatmap = $exam->examQuestions->map(function($eq) use($sessions) {
            $q=$eq->question; $correct=0; $answered=0;
            foreach($sessions as $s) {
                $ans=($s->answers??[])[$q->id]??null; if($ans===null) continue; $answered++;
                if($q->type==='mcq') { $ci=collect($q->options??[])->search(fn($o)=>$o['correct']??false); if($ans===$ci||$ans===(string)$ci) $correct++; }
                elseif($q->type==='truefalse') { if(strtolower((string)$ans)===strtolower($q->answer??'')) $correct++; }
            }
            return ['question_id'=>$q->id,'text'=>substr($q->text,0,80),'type'=>$q->type,'difficulty'=>$q->difficulty,'order'=>$eq->order,'answered'=>$answered,'correct'=>$correct,'correct_pct'=>$answered>0?round($correct/$answered*100):null];
        })->sortBy('order')->values();
        return response()->json(['heatmap'=>$heatmap]);
    }

    public function cohortAnalysis(string $examId): JsonResponse {
        $exam = Exam::findOrFail($examId);
        $sessions = ExamSession::with('user')->where('exam_id',$examId)->where('status','submitted')->get();
        $graded = $sessions->filter(fn($s)=>$s->final_score!==null);
        return response()->json(['cohort_size'=>$sessions->count(),'graded'=>$graded->count(),'pass_rate'=>$graded->count()>0?round($graded->where('passed',true)->count()/$graded->count()*100,1):0,'avg_score'=>round($graded->avg('final_score')??0,1),'at_risk'=>$graded->filter(fn($s)=>$s->final_score<$exam->pass_mark)->map(fn($s)=>['user'=>$s->user->toPublicArray(),'score'=>$s->final_score,'tab_switches'=>$s->tab_switches])->values()]);
    }

    public function studentProgress(string $userId): JsonResponse {
        $sessions = ExamSession::with('exam:id,title,pass_mark')->where('user_id',$userId)->where('status','submitted')->whereNotNull('final_score')->latest('submitted_at')->get();
        return response()->json(['exams_taken'=>$sessions->count(),'avg_score'=>round($sessions->avg('final_score')??0,1),'best_score'=>$sessions->max('final_score'),'pass_rate'=>$sessions->count()>0?round($sessions->where('passed',true)->count()/$sessions->count()*100,1):0,'history'=>$sessions->map(fn($s)=>['exam_title'=>$s->exam->title,'score'=>$s->final_score,'passed'=>$s->passed,'submitted_at'=>$s->submitted_at])->values()]);
    }

    public function platformStats(): JsonResponse {
        return response()->json(['exams'=>Exam::count(),'questions'=>Question::count(),'users'=>\App\Models\User::count(),'submissions'=>ExamSession::where('status','submitted')->count(),'certificates'=>\App\Models\Certificate::count(),'badges'=>\App\Models\Badge::count()]);
    }

    public function aiInsights(Request $request): JsonResponse {
        $data = $request->validate(['type'=>'required|string','context'=>'nullable|array']);
        return response()->json(['insights'=>$this->ai->generateReport($data['type'],$data['context']??[])]);
    }

    public function export(): \Symfony\Component\HttpFoundation\StreamedResponse {
        return response()->stream(function() {
            $h=fopen('php://output','w'); fputcsv($h,['Student','Email','Exam','Score','Passed','Submitted']);
            ExamSession::with(['user:id,name,email','exam:id,title'])->where('status','submitted')->chunk(500,function($ss) use($h){ foreach($ss as $s) fputcsv($h,[$s->user->name,$s->user->email,$s->exam->title,$s->final_score??'Pending',$s->passed?'Yes':'No',$s->submitted_at]); });
            fclose($h);
        },200,['Content-Type'=>'text/csv','Content-Disposition'=>'attachment; filename=grades.csv']);
    }
}
