<?php
namespace App\Services;
use Illuminate\Support\Facades\{Http, Log};

class AiService {
    private string $apiKey;
    private string $model;
    private string $url = 'https://api.anthropic.com/v1/messages';

    public function __construct() {
        $this->apiKey = config('services.anthropic.key');
        $this->model  = config('services.anthropic.model','claude-sonnet-4-20250514');
    }

    public function gradeEssay(string $question, string $modelAnswer, string $studentAnswer, int $maxPoints): array {
        $prompt = "You are an expert examiner. Grade this essay.\nQUESTION ({$maxPoints} pts): {$question}\nMODEL ANSWER: {$modelAnswer}\nSTUDENT ANSWER: {$studentAnswer}\n\nRespond ONLY with JSON:\n{\"similarity\":0-100,\"score\":0-100,\"awarded\":0-{$maxPoints},\"grade\":\"A\",\"confidence\":60-99,\"rubric\":[{\"criterion\":\"...\",\"score\":0,\"comment\":\"...\"}],\"keywords\":{\"found\":[],\"partial\":[],\"missing\":[]},\"feedback\":\"...\",\"plagiarism\":false,\"ai_generated\":false}";
        return $this->parseJson($this->call($prompt,800)) ?? $this->fallbackGrade($maxPoints);
    }

    public function evaluateCode(string $question, string $code, string $tests, string $lang, int $maxPoints): array {
        $prompt = "Senior {$lang} engineer: evaluate this code.\nPROBLEM: {$question}\nTESTS: {$tests}\nCODE: {$code}\nMAX: {$maxPoints}\n\nRespond ONLY with JSON:\n{\"passes_all\":true,\"score\":0-100,\"awarded\":0-{$maxPoints},\"grade\":\"A\",\"confidence\":70-99,\"time_complexity\":\"O(...)\",\"space_complexity\":\"O(...)\",\"test_results\":[],\"bugs\":[],\"strengths\":[],\"suggestion\":\"...\",\"feedback\":\"...\"}";
        return $this->parseJson($this->call($prompt,700)) ?? $this->fallbackCode($maxPoints);
    }

    public function checkPlagiarism(string $text, array $refs = []): array {
        $refsText = $refs ? "\nREFS:\n".implode("\n---\n",array_slice($refs,0,5)) : '';
        $prompt = "Analyse for plagiarism/AI-generation.\nTEXT: \"{$text}\"{$refsText}\n\nRespond ONLY with JSON:\n{\"originality\":100,\"ai_generated_risk\":0,\"similarity_to_refs\":0,\"verdict\":\"clean\",\"flags\":[],\"explanation\":\"...\",\"recommendation\":\"...\"}";
        return $this->parseJson($this->call($prompt)) ?? ['originality'=>100,'ai_generated_risk'=>0,'similarity_to_refs'=>0,'verdict'=>'clean','flags'=>[],'explanation'=>'Analysis unavailable.','recommendation'=>'Manual review.'];
    }

    public function generateQuestions(string $topic, string $type, string $difficulty, int $count, int $points, string $ctx = ''): array {
        $prompt = "Generate exactly {$count} {$type} questions about \"{$topic}\" at {$difficulty} difficulty, {$points} pts each.\n{$ctx}\n\nRespond ONLY with JSON array:\n[{\"type\":\"{$type}\",\"text\":\"...\",\"options\":[{\"text\":\"...\",\"correct\":false}],\"answer\":\"...\",\"explanation\":\"...\",\"points\":{$points},\"difficulty\":\"{$difficulty}\",\"tags\":[]}]";
        return $this->parseJsonArray($this->call($prompt,1500)) ?? [];
    }

    public function improveQuestion(string $q, string $type, string $diff, string $goal): string {
        $goals=['clarity'=>'Make clearer','bloom'=>"Higher Bloom's taxonomy",'realworld'=>'Add real-world context','distractors'=>'Improve MCQ distractors','antiAI'=>'Make AI-resistant'];
        $prompt = "Improve this {$type} question for {$diff} difficulty.\nORIGINAL: \"{$q}\"\nGOAL: ".($goals[$goal]??$goal)."\n\nProvide: 1) IMPROVED QUESTION 2) Options if MCQ 3) EXPLANATION 4) QUALITY SCORE (1-10)";
        return $this->call($prompt,700) ?? 'Improvement unavailable.';
    }

    public function generateMarkScheme(string $desc, int $passMark): string {
        return $this->call("Generate detailed mark scheme for: \"{$desc}\". Pass: {$passMark}%. Include grade boundaries A-F, partial credit policy, marking guidelines.",700) ?? 'Failed.';
    }

    public function generateRubric(string $topic, int $total): array {
        return $this->parseJsonArray($this->call("Create {$total}-point rubric for \"{$topic}\". Respond ONLY with JSON array: [{\"name\":\"criterion\",\"pts\":N,\"desc\":\"...\"}] totaling exactly {$total}.",400)) ?? [];
    }

    public function generateStudentFeedback(string $name, string $exam, int $score, int $passMark, string $weak, string $strong, string $tone): string {
        return $this->call("Write personalised {$tone} feedback for {$name}. Exam: {$exam}. Score: {$score}% (pass: {$passMark}%). Strong: {$strong}. Weak: {$weak}. Write 3-4 paragraphs with next steps.",600) ?? 'Unavailable.';
    }

    public function generateReport(string $type, array $data): string {
        $types=['performance'=>'Performance Summary','difficulty'=>'Question Difficulty Analysis','proctoring'=>'Academic Integrity Report','recommendations'=>'Strategic Recommendations'];
        return $this->call("Write professional ".($types[$type]??$type)." based on:\n".json_encode($data),800) ?? 'Unavailable.';
    }

    public function reviewAppeal(array $appeal): string {
        return $this->call("Academic appeals officer. Review:\nStudent: {$appeal['email']}\nExam: {$appeal['exam_title']}\nScore: {$appeal['original_score']}%\nReason: {$appeal['reason']}\nStatement: \"{$appeal['statement']}\"\n\nRecommend: uphold, modify (with score), or reject. 3-4 evidence-based sentences.",400) ?? 'Unavailable.';
    }

    public function cohortAnalysis(array $stats): string {
        return $this->call("Analyse this exam cohort, provide 4-5 insights and interventions.\n".json_encode($stats),600) ?? 'Unavailable.';
    }

    public function securityAudit(array $ctx): string {
        return $this->call("Security audit: 5-6 prioritised recommendations with severity.\n".json_encode($ctx),600) ?? 'Unavailable.';
    }

    public function translate(string $text, string $lang): string {
        return $this->call("Translate to {$lang}. Return ONLY translated text:\n\n{$text}",400) ?? $text;
    }

    public function aiInsights(string $prompt): string {
        return $this->call($prompt,800) ?? 'Unavailable.';
    }

    private function call(string $prompt, int $maxTokens = 900): ?string {
        try {
            $r = Http::withHeaders(['x-api-key'=>$this->apiKey,'anthropic-version'=>'2023-06-01','content-type'=>'application/json'])->timeout(60)->retry(2,1000)->post($this->url,['model'=>$this->model,'max_tokens'=>$maxTokens,'messages'=>[['role'=>'user','content'=>$prompt]]]);
            if (!$r->successful()) { Log::error('Claude API error',['status'=>$r->status()]); return null; }
            return $r->json('content.0.text');
        } catch (\Exception $e) { Log::error('Claude API exception',['error'=>$e->getMessage()]); return null; }
    }

    private function parseJson(?string $t): ?array {
        if (!$t) return null;
        try { $c=trim(preg_replace('/```json|```/','',$t)); if(preg_match('/\{[\s\S]*\}/',$c,$m)) return json_decode($m[0],true,512,JSON_THROW_ON_ERROR); return json_decode($c,true,512,JSON_THROW_ON_ERROR); } catch(\Exception $e) { return null; }
    }

    private function parseJsonArray(?string $t): ?array {
        if (!$t) return null;
        try { $c=trim(preg_replace('/```json|```/','',$t)); if(preg_match('/\[[\s\S]*\]/',$c,$m)) return json_decode($m[0],true,512,JSON_THROW_ON_ERROR); return json_decode($c,true,512,JSON_THROW_ON_ERROR); } catch(\Exception $e) { return null; }
    }

    private function fallbackGrade(int $max): array { return ['similarity'=>0,'score'=>0,'awarded'=>0,'grade'=>'F','confidence'=>0,'rubric'=>[],'keywords'=>['found'=>[],'partial'=>[],'missing'=>[]],'feedback'=>'Grading failed. Manual review required.','plagiarism'=>false,'ai_generated'=>false]; }
    private function fallbackCode(int $max): array { return ['passes_all'=>false,'score'=>0,'awarded'=>0,'grade'=>'F','confidence'=>0,'time_complexity'=>'—','space_complexity'=>'—','test_results'=>[],'bugs'=>['Evaluation failed'],'strengths'=>[],'suggestion'=>'Manual review required.','feedback'=>'Code evaluation failed.']; }
}
