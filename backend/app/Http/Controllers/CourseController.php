<?php
namespace App\Http\Controllers;
use App\Models\{Course, CourseEnrollment};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class CourseController extends Controller {
    public function index(Request $request): JsonResponse { return response()->json(Course::withCount('enrollments')->latest()->paginate($request->per_page??20)); }
    public function store(Request $request): JsonResponse {
        $data = $request->validate(['name'=>'required|string','code'=>'required|string|max:20','description'=>'nullable|string']);
        $course = Course::create(['id'=>Str::uuid(),'created_by'=>auth()->id(),'code'=>$data['code'],'name'=>$data['name'],'description'=>$data['description']??null,'join_code'=>strtoupper(Str::random(6))]);
        return response()->json(['course'=>$course], 201);
    }
    public function show(string $id): JsonResponse { return response()->json(['course'=>Course::withCount('enrollments')->findOrFail($id)]); }
    public function update(Request $request, string $id): JsonResponse { $course=Course::findOrFail($id); $course->update($request->only(['name','description','is_active'])); return response()->json(['course'=>$course->fresh()]); }
    public function destroy(string $id): JsonResponse { Course::findOrFail($id)->delete(); return response()->json(['message'=>'Course deleted.']); }
    public function joinByCode(Request $request): JsonResponse {
        $data = $request->validate(['join_code'=>'required|string']);
        $course = Course::where('join_code',strtoupper($data['join_code']))->firstOrFail();
        CourseEnrollment::firstOrCreate(['course_id'=>$course->id,'user_id'=>auth()->id()],['id'=>Str::uuid()]);
        return response()->json(['message'=>'Joined course.','course'=>$course]);
    }
    public function students(string $id): JsonResponse {
        $course = Course::findOrFail($id);
        return response()->json(['students'=>$course->enrollments()->with('user')->get()->map(fn($e)=>$e->user->toPublicArray())]);
    }
}
