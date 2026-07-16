import axios, { AxiosInstance, AxiosError, InternalAxiosRequestConfig } from 'axios';

const BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1';
const TOKEN_KEY = 'examedge_token';

export const tokenStorage = {
  get:    ()          => localStorage.getItem(TOKEN_KEY),
  set:    (t: string) => localStorage.setItem(TOKEN_KEY, t),
  clear:  ()          => localStorage.removeItem(TOKEN_KEY),
};

const api: AxiosInstance = axios.create({
  baseURL: BASE_URL, timeout: 60000,
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
});

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = tokenStorage.get();
  if (token && config.headers) config.headers['Authorization'] = `Bearer ${token}`;
  return config;
});

let isRefreshing = false;
let failedQueue: Array<{ resolve: (v: unknown) => void; reject: (r: unknown) => void }> = [];
const processQueue = (error: AxiosError | null, token: string | null = null) => {
  failedQueue.forEach(p => error ? p.reject(error) : p.resolve(token));
  failedQueue = [];
};

api.interceptors.response.use(
  r => r,
  async (error: AxiosError) => {
    const orig = error.config as InternalAxiosRequestConfig & { _retry?: boolean };
    if (error.response?.status === 401 && !orig._retry) {
      if (isRefreshing) return new Promise((resolve, reject) => failedQueue.push({ resolve, reject })).then(t => { if (orig.headers) orig.headers['Authorization'] = `Bearer ${t}`; return api(orig); });
      orig._retry = true; isRefreshing = true;
      try {
        const { data } = await axios.post(`${BASE_URL}/auth/refresh`, {}, { headers: { Authorization: `Bearer ${tokenStorage.get()}` } });
        tokenStorage.set(data.token); processQueue(null, data.token);
        if (orig.headers) orig.headers['Authorization'] = `Bearer ${data.token}`;
        return api(orig);
      } catch (e) { processQueue(e as AxiosError, null); tokenStorage.clear(); window.location.href = '/login'; return Promise.reject(e); }
      finally { isRefreshing = false; }
    }
    if (error.response?.status === 422) return Promise.reject({ message: 'Validation failed', errors: (error.response.data as { errors?: Record<string, string[]> }).errors ?? {} });
    return Promise.reject((error.response?.data as { message?: string })?.message ? error.response?.data : { message: error.message || 'Network error' });
  }
);

export const authApi = {
  register:        (d: object) => api.post('/auth/register', d),
  login:           (d: { email: string; password: string }) => api.post('/auth/login', d),
  verifyTwoFactor: (d: { user_id: string; code: string }) => api.post('/auth/verify-2fa', d),
  logout:          () => api.post('/auth/logout'),
  me:              () => api.get('/auth/me'),
  refresh:         () => api.post('/auth/refresh'),
  forgotPassword:  (email: string) => api.post('/auth/forgot-password', { email }),
  resetPassword:   (d: object) => api.post('/auth/reset-password', d),
  setupTwoFactor:  () => api.post('/auth/setup-2fa'),
  enableTwoFactor: (code: string) => api.post('/auth/enable-2fa', { code }),
  disableTwoFactor:(password: string) => api.post('/auth/disable-2fa', { password }),
  getBackupCodes:  () => api.get('/auth/backup-codes'),
};

export const examApi = {
  list:            (p?: object) => api.get('/exams', { params: p }),
  create:          (d: object)  => api.post('/exams', d),
  show:            (id: string) => api.get(`/exams/${id}`),
  update:          (id: string, d: object) => api.put(`/exams/${id}`, d),
  delete:          (id: string) => api.delete(`/exams/${id}`),
  publish:         (id: string) => api.post(`/exams/${id}/publish`),
  archive:         (id: string) => api.post(`/exams/${id}/archive`),
  duplicate:       (id: string, d: object) => api.post(`/exams/${id}/duplicate`, d),
  updateLifecycle: (id: string, stage: string) => api.put(`/exams/${id}/lifecycle`, { stage }),
  schedule:        (id: string, d: object) => api.post(`/exams/${id}/schedule`, d),
  publishResults:  (id: string, d: object) => api.post(`/exams/${id}/publish-results`, d),
  results:         (id: string) => api.get(`/exams/${id}/results`),
  statistics:      (id: string) => api.get(`/exams/${id}/statistics`),
  getQuestions:    (id: string) => api.get(`/exams/${id}/questions`),
  addQuestion:     (id: string, d: object) => api.post(`/exams/${id}/questions`, d),
  removeQuestion:  (id: string, qId: string) => api.delete(`/exams/${id}/questions/${qId}`),
  reorderQuestions:(id: string, order: string[]) => api.put(`/exams/${id}/questions/reorder`, { order }),
  updateAttemptPolicy: (id: string, d: object) => api.put(`/exams/${id}/attempt-policy`, d),
};

export const questionApi = {
  list:       (p?: object)           => api.get('/questions', { params: p }),
  create:     (d: object)            => api.post('/questions', d),
  show:       (id: string)           => api.get(`/questions/${id}`),
  update:     (id: string, d: object)=> api.put(`/questions/${id}`, d),
  delete:     (id: string)           => api.delete(`/questions/${id}`),
  versions:   (id: string)           => api.get(`/questions/${id}/versions`),
  restore:    (id: string, v: number)=> api.post(`/questions/${id}/restore`, { version: v }),
  bulkImport: (d: object)            => api.post('/questions/bulk-import', d),
  aiGenerate: (d: object)            => api.post('/questions/ai-generate', d),
  aiImprove:  (id: string, d: object)=> api.post(`/questions/${id}/improve`, d),
};

export const sessionApi = {
  start:        (examId: string)     => api.post('/sessions', { exam_id: examId }),
  show:         (id: string)         => api.get(`/sessions/${id}`),
  saveAnswer:   (id: string, d: object) => api.put(`/sessions/${id}/answer`, d),
  flagQuestion: (id: string, qId: string) => api.put(`/sessions/${id}/flag`, { question_id: qId }),
  submit:       (id: string, answers?: object) => api.post(`/sessions/${id}/submit`, { answers }),
  recover:      (id: string)         => api.post(`/sessions/${id}/recover`),
  result:       (id: string)         => api.get(`/sessions/${id}/result`),
  active:       ()                   => api.get('/sessions/active'),
};

export const gradingApi = {
  queue:          (p?: object)           => api.get('/grading/queue', { params: p }),
  gradeSession:   (sid: string)          => api.post(`/grading/grade/${sid}`),
  gradeEssay:     (d: object)            => api.post('/grading/grade-essay', d),
  approve:        (id: string)           => api.post(`/grading/${id}/approve`),
  override:       (id: string, d: object)=> api.post(`/grading/${id}/override`, d),
  checkPlagiarism:(d: object)            => api.post('/grading/check-plagiarism', d),
  evaluateCode:   (d: object)            => api.post('/grading/evaluate-code', d),
};

export const proctoringApi = {
  logViolation:   (d: object)     => api.post('/proctoring/log-violation', d),
  sessionLogs:    (sid: string)   => api.get(`/proctoring/session/${sid}`),
  examLogs:       (eid: string)   => api.get(`/proctoring/exam/${eid}`),
  saveScreenshot: (d: object)     => api.post('/proctoring/screenshot', d),
  terminate:      (sid: string)   => api.post(`/proctoring/terminate/${sid}`),
};

export const analyticsApi = {
  overview:        ()            => api.get('/analytics/overview'),
  examAnalytics:   (id: string)  => api.get(`/analytics/exam/${id}`),
  heatmap:         (id: string)  => api.get(`/analytics/exam/${id}/heatmap`),
  cohort:          (id: string)  => api.get(`/analytics/cohort/${id}`),
  studentProgress: (uid: string) => api.get(`/analytics/student/${uid}`),
  platformStats:   ()            => api.get('/analytics/platform'),
  aiInsights:      (d: object)   => api.post('/analytics/ai-insights', d),
  export:          (p?: object)  => api.get('/analytics/export', { params: p, responseType: 'blob' }),
};

export const userApi = {
  list:         (p?: object)           => api.get('/users', { params: p }),
  show:         (id: string)           => api.get(`/users/${id}`),
  update:       (id: string, d: object)=> api.put(`/users/${id}`, d),
  delete:       (id: string)           => api.delete(`/users/${id}`),
  suspend:      (id: string)           => api.post(`/users/${id}/suspend`),
  activate:     (id: string)           => api.post(`/users/${id}/activate`),
  progress:     (id: string)           => api.get(`/users/${id}/progress`),
  badges:       (id: string)           => api.get(`/users/${id}/badges`),
  certificates: (id: string)           => api.get(`/users/${id}/certificates`),
};

export const courseApi = {
  list:     (p?: object)           => api.get('/courses', { params: p }),
  create:   (d: object)            => api.post('/courses', d),
  show:     (id: string)           => api.get(`/courses/${id}`),
  update:   (id: string, d: object)=> api.put(`/courses/${id}`, d),
  delete:   (id: string)           => api.delete(`/courses/${id}`),
  join:     (code: string)         => api.post('/courses/join', { join_code: code }),
  students: (id: string)           => api.get(`/courses/${id}/students`),
};

export const certificateApi = {
  list:     (p?: object)   => api.get('/certificates', { params: p }),
  issue:    (d: object)    => api.post('/certificates', d),
  show:     (id: string)   => api.get(`/certificates/${id}`),
  verify:   (hash: string) => api.get(`/certificates/verify/${hash}`),
  ledger:   ()             => api.get('/certificates/ledger'),
  download: (id: string)   => api.get(`/certificates/${id}/download`),
};

export const notificationApi = {
  list:        (p?: object) => api.get('/notifications', { params: p }),
  send:        (d: object)  => api.post('/notifications', d),
  markRead:    (id: string) => api.put(`/notifications/${id}/read`),
  markAllRead: ()           => api.post('/notifications/read-all'),
  delete:      (id: string) => api.delete(`/notifications/${id}`),
  unreadCount: ()           => api.get('/notifications/unread-count'),
};

export const webhookApi = {
  list:   (p?: object)           => api.get('/webhooks', { params: p }),
  create: (d: object)            => api.post('/webhooks', d),
  update: (id: string, d: object)=> api.put(`/webhooks/${id}`, d),
  delete: (id: string)           => api.delete(`/webhooks/${id}`),
  test:   (id: string)           => api.post(`/webhooks/${id}/test`),
  logs:   (id: string)           => api.get(`/webhooks/${id}/logs`),
};

export const appealApi = {
  list:     (p?: object)           => api.get('/appeals', { params: p }),
  create:   (d: object)            => api.post('/appeals', d),
  show:     (id: string)           => api.get(`/appeals/${id}`),
  decide:   (id: string, d: object)=> api.put(`/appeals/${id}/decide`, d),
  aiReview: (id: string)           => api.post(`/appeals/${id}/ai-review`),
};

export const auditApi = {
  list:   (p?: object) => api.get('/audit', { params: p }),
  export: (p?: object) => api.get('/audit/export', { params: p, responseType: 'blob' }),
};

export default api;
