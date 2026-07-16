export type UserRole = 'student' | 'instructor' | 'admin';
export type ExamStatus = 'draft' | 'review' | 'published' | 'active' | 'closed' | 'grading' | 'results' | 'archived';
export type QuestionType = 'mcq' | 'essay' | 'coding' | 'truefalse' | 'fillin' | 'multimedia';
export type Difficulty = 'easy' | 'medium' | 'hard';
export type ProctoringLevel = 'none' | 'basic' | 'full';
export type GradeStatus = 'pending' | 'pending_ai' | 'graded';
export type SessionStatus = 'in_progress' | 'submitted' | 'abandoned';

export interface User {
  id: string; name: string; email: string; role: UserRole;
  institution?: string; timezone: string; locale: string;
  is_active: boolean; two_factor_enabled: boolean; created_at: string;
}
export interface Course {
  id: string; code: string; name: string; description?: string;
  join_code: string; is_active: boolean; created_at: string;
}
export interface MCQOption { text: string; correct?: boolean; }
export interface Question {
  id: string; type: QuestionType; text: string; options?: MCQOption[];
  answer?: string; explanation?: string; points: number; difficulty: Difficulty;
  tags: string[]; version: number; created_at: string;
}
export interface ExamQuestion {
  id: string; exam_id: string; question_id: string; order: number;
  points_override?: number; question: Question;
}
export interface Exam {
  id: string; title: string; description?: string; course_id?: string;
  course?: Course; duration: number; pass_mark: number;
  proctoring_level: ProctoringLevel; status: ExamStatus;
  shuffle_questions: boolean; shuffle_options: boolean;
  max_attempts: number; attempt_cooldown: number;
  window_start?: string; window_end?: string; timezone: string;
  results_published: boolean; total_submissions: number;
  questions_count?: number; sessions_count?: number; created_at: string;
}
export interface ExamSession {
  id: string; exam_id: string; exam_title: string; exam_duration: number;
  time_left: number; current_question: number;
  answers: Record<string, unknown>; flagged: string[];
  status: SessionStatus; grade_status: GradeStatus;
  questions: Question[]; total_questions: number;
  last_saved_at?: string; attempt_number: number;
  proctoring_level: ProctoringLevel; final_score?: number; passed?: boolean;
}
export interface Certificate {
  id: string; user_id: string; exam_id: string;
  type: 'completion' | 'excellence' | 'distinction' | 'participation';
  institution: string; hash: string; block_number: number;
  final_score: number; issued_at: string;
}
export interface Badge {
  id: string; user_id: string; type: string; reason?: string; created_at: string;
}
export interface Notification {
  id: string; title: string; body: string; type: string; read: boolean; created_at: string;
}
export interface ApiResponse<T> { data?: T; message?: string; errors?: Record<string, string[]>; }
export interface PaginatedResponse<T> { data: T[]; current_page: number; last_page: number; per_page: number; total: number; }
