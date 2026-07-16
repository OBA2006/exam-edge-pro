<?php
namespace Database\Seeders;

use App\Models\{User, Course, Exam, Question, ExamQuestion, CourseEnrollment};
use Illuminate\Database\Seeder;
use Illuminate\Support\{Str, Facades\Hash};

class DatabaseSeeder extends Seeder {
    public function run(): void {
        $this->call([TenantSeeder::class, UserSeeder::class, CourseSeeder::class, ExamSeeder::class]);
    }
}

class TenantSeeder extends Seeder {
    public function run(): void {
        \App\Models\Tenant::firstOrCreate(['slug'=>'demo'],['id'=>Str::uuid(),'name'=>'Demo University','slug'=>'demo','admin_email'=>'admin@demo.edu','plan'=>'enterprise','primary_color'=>'#1a7f5a','is_active'=>true]);
    }
}

class UserSeeder extends Seeder {
    public function run(): void {
        $tenant = \App\Models\Tenant::where('slug','demo')->first();
        $users = [
            ['name'=>'Admin User',       'email'=>'admin@examedge.pro',       'role'=>'admin'],
            ['name'=>'Dr. Sarah Okafor', 'email'=>'instructor@examedge.pro',  'role'=>'instructor'],
            ['name'=>'Dr. James Adeyemi','email'=>'instructor2@examedge.pro', 'role'=>'instructor'],
            ['name'=>'Alice Chen',       'email'=>'alice@student.edu',        'role'=>'student'],
            ['name'=>'Marcus Liu',       'email'=>'marcus@student.edu',       'role'=>'student'],
            ['name'=>'Priya Sharma',     'email'=>'priya@student.edu',        'role'=>'student'],
            ['name'=>'James Okafor',     'email'=>'james@student.edu',        'role'=>'student'],
            ['name'=>'Fatima Al-Hassan', 'email'=>'fatima@student.edu',       'role'=>'student'],
        ];
        foreach ($users as $data) {
            User::firstOrCreate(['email'=>$data['email']],['id'=>Str::uuid(),'tenant_id'=>$tenant?->id,'name'=>$data['name'],'email'=>$data['email'],'password'=>Hash::make('password'),'role'=>$data['role'],'institution'=>'Demo University','is_active'=>true]);
        }
        $this->command->info('Users seeded (password: password)');
    }
}

class CourseSeeder extends Seeder {
    public function run(): void {
        $instructor = User::where('role','instructor')->first();
        $courses = [
            ['code'=>'CS401', 'name'=>'Algorithms & Data Structures','join_code'=>'CS4017'],
            ['code'=>'MBA502','name'=>'Corporate Finance Theory',    'join_code'=>'MBA502'],
            ['code'=>'BIO301','name'=>'Molecular Cell Biology',      'join_code'=>'BIO301'],
        ];
        foreach ($courses as $data) {
            $course = Course::firstOrCreate(['code'=>$data['code']],['id'=>Str::uuid(),'created_by'=>$instructor->id]+$data+['is_active'=>true]);
        }
        // Enrol all students in CS401
        $cs401 = Course::where('code','CS401')->first();
        User::where('role','student')->get()->each(fn($u) =>
            CourseEnrollment::firstOrCreate(['course_id'=>$cs401->id,'user_id'=>$u->id],['id'=>Str::uuid()])
        );
        $this->command->info('Courses seeded + students enrolled in CS401');
    }
}

class ExamSeeder extends Seeder {
    public function run(): void {
        if (Exam::count() > 0) return;
        $instructor = User::where('role','instructor')->first();
        $course = Course::where('code','CS401')->first();
        $exam = Exam::create(['id'=>Str::uuid(),'created_by'=>$instructor->id,'course_id'=>$course?->id,'title'=>'CS401 Algorithms Final','description'=>'Comprehensive final covering sorting, graphs, and dynamic programming.','duration'=>90,'pass_mark'=>50,'proctoring_level'=>'basic','status'=>'published','shuffle_questions'=>true,'shuffle_options'=>true,'max_attempts'=>2]);

        $questions = [
            ['type'=>'mcq','text'=>'What is the time complexity of binary search on a sorted array of n elements?','options'=>[['text'=>'O(n) — linear scan','correct'=>false],['text'=>'O(log n) — halves search space each step','correct'=>true],['text'=>'O(n^2) — compares each pair','correct'=>false],['text'=>'O(1) — constant time','correct'=>false]],'explanation'=>'Binary search eliminates half the remaining elements at each step.','difficulty'=>'medium','points'=>5,'tags'=>['algorithms','binary-search','complexity']],
            ['type'=>'mcq','text'=>'Which data structure implements breadth-first search (BFS)?','options'=>[['text'=>'Stack','correct'=>false],['text'=>'Queue','correct'=>true],['text'=>'Heap','correct'=>false],['text'=>'Linked list','correct'=>false]],'explanation'=>'BFS uses a FIFO queue to explore level by level.','difficulty'=>'easy','points'=>4,'tags'=>['graphs','bfs']],
            ['type'=>'mcq','text'=>'What is the space complexity of merge sort?','options'=>[['text'=>'O(1)','correct'=>false],['text'=>'O(log n)','correct'=>false],['text'=>'O(n)','correct'=>true],['text'=>'O(n log n)','correct'=>false]],'explanation'=>'Merge sort requires O(n) auxiliary space for the merge step.','difficulty'=>'medium','points'=>5,'tags'=>['sorting']],
            ['type'=>'truefalse','text'=>'A binary search tree (BST) guarantees O(log n) search time in all cases.','answer'=>'false','explanation'=>'A BST is O(log n) on average but degrades to O(n) when unbalanced.','difficulty'=>'medium','points'=>3,'tags'=>['bst']],
            ['type'=>'truefalse','text'=>"Dijkstra's algorithm works correctly on graphs with negative edge weights.",'answer'=>'false','explanation'=>'Dijkstra fails with negative weights; use Bellman-Ford instead.','difficulty'=>'hard','points'=>3,'tags'=>['graphs','shortest-path']],
            ['type'=>'fillin','text'=>'The technique of storing results of overlapping subproblems to avoid redundant computation is called ___________.','answer'=>'memoization','difficulty'=>'medium','points'=>4,'tags'=>['dynamic-programming']],
            ['type'=>'essay','text'=>'Explain dynamic programming and how it differs from divide-and-conquer. Provide an example algorithm and explain why it is more efficient than a naive recursive solution.','answer'=>'Dynamic programming (DP) solves optimization problems by decomposing them into overlapping subproblems with optimal substructure, storing results using memoization or tabulation. Unlike divide-and-conquer (which splits independent subproblems), DP handles interdependent ones. The Fibonacci sequence shows this: naive recursion is O(2^n), while DP is O(n) by caching results. The Knapsack problem demonstrates polynomial vs exponential complexity reduction.','difficulty'=>'hard','points'=>15,'tags'=>['dynamic-programming']],
            ['type'=>'coding','text'=>'Implement two_sum(nums, target) in Python that returns indices of two numbers adding to target. Must run in O(n) time.','answer'=>"def two_sum(nums, target):
    seen = {}
    for i, num in enumerate(nums):
        comp = target - num
        if comp in seen:
            return [seen[comp], i]
        seen[num] = i
    return []
# Tests: two_sum([2,7,11,15],9)==[0,1]  two_sum([3,2,4],6)==[1,2]",'difficulty'=>'medium','points'=>12,'tags'=>['python','hash-table']],
        ];

        foreach ($questions as $i => $qd) {
            $q = Question::create(['id'=>Str::uuid(),'created_by'=>$instructor->id,'type'=>$qd['type'],'text'=>$qd['text'],'options'=>$qd['options']??null,'answer'=>$qd['answer']??null,'explanation'=>$qd['explanation']??null,'difficulty'=>$qd['difficulty'],'points'=>$qd['points'],'tags'=>$qd['tags']]);
            ExamQuestion::create(['id'=>Str::uuid(),'exam_id'=>$exam->id,'question_id'=>$q->id,'order'=>$i]);
        }
        $this->command->info('Demo exam seeded: CS401 Algorithms Final with '.count($questions).' questions');
    }
}
