<?php
namespace App\Services;
use App\Models\{Exam, ExamSession};

class GradingService {
    public function autoGrade(ExamSession $session, Exam $exam): array {
        $answers = $session->answers ?? [];
        $autoScore = 0; $total = 0; $essayItems = [];

        foreach ($exam->examQuestions()->with('question')->orderBy('order')->get() as $eq) {
            $q = $eq->question; $pts = $eq->points_override ?? $q->points;
            $total += $pts;
            $answer = $answers[$q->id] ?? $answers[$eq->order] ?? null;
            if ($answer === null) continue;
            switch ($q->type) {
                case 'mcq':
                    $ci = collect($q->options??[])->search(fn($o)=>$o['correct']??false);
                    if ($answer===$ci || $answer===(string)$ci) $autoScore += $pts;
                    break;
                case 'truefalse':
                    if (strtolower((string)$answer)===strtolower((string)($q->answer??''))) $autoScore += $pts;
                    break;
                case 'fillin':
                    $correct=trim(strtolower($q->answer??'')); $given=trim(strtolower((string)$answer));
                    if ($correct && ($given===$correct || similar_text($given,$correct)/max(strlen($correct),1)>0.85)) $autoScore += $pts;
                    break;
                case 'essay': case 'coding':
                    if (!empty($answer)) $essayItems[] = ['question_id'=>$q->id,'answer'=>$answer,'max_points'=>$pts];
                    break;
            }
        }
        $hasEssays = count($essayItems) > 0;
        return ['auto_score'=>$autoScore,'total_possible'=>$total,'percentage'=>$hasEssays?null:($total>0?round($autoScore/$total*100):0),'has_essays'=>$hasEssays,'essay_items'=>$essayItems];
    }
}
