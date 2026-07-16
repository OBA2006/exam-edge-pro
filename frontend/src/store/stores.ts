import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import { authApi, examApi, sessionApi, proctoringApi, notificationApi, tokenStorage } from '../utils/api';
import type { User, Exam, ExamSession, Notification } from '../types';

// ── Auth Store ──────────────────────────────────────────────
interface AuthState {
  user: User | null; token: string | null; isLoading: boolean; error: string | null;
  twoFactorPending: { userId: string } | null;
  login: (email: string, password: string) => Promise<void>;
  register: (data: object) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
  verifyTwoFactor: (code: string) => Promise<void>;
  clearError: () => void;
  setUser: (user: User) => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null, token: null, isLoading: false, error: null, twoFactorPending: null,

      login: async (email, password) => {
        set({ isLoading: true, error: null });
        try {
          const { data } = await authApi.login({ email, password });
          if (data.two_factor_required) { set({ twoFactorPending: { userId: data.user_id }, isLoading: false }); return; }
          tokenStorage.set(data.token);
          set({ user: data.user, token: data.token, isLoading: false, twoFactorPending: null });
        } catch (err: unknown) { set({ error: (err as {message?:string})?.message || 'Login failed', isLoading: false }); throw err; }
      },

      register: async (formData) => {
        set({ isLoading: true, error: null });
        try {
          const { data } = await authApi.register(formData);
          tokenStorage.set(data.token);
          set({ user: data.user, token: data.token, isLoading: false });
        } catch (err: unknown) { set({ error: (err as {message?:string})?.message || 'Registration failed', isLoading: false }); throw err; }
      },

      verifyTwoFactor: async (code) => {
        const pending = get().twoFactorPending;
        if (!pending) throw new Error('No 2FA pending');
        set({ isLoading: true, error: null });
        try {
          const { data } = await authApi.verifyTwoFactor({ user_id: pending.userId, code });
          tokenStorage.set(data.token);
          set({ user: data.user, token: data.token, twoFactorPending: null, isLoading: false });
        } catch { set({ error: 'Invalid code', isLoading: false }); throw new Error('Invalid code'); }
      },

      logout: async () => {
        try { await authApi.logout(); } catch { }
        tokenStorage.clear();
        set({ user: null, token: null, twoFactorPending: null });
      },

      refreshUser: async () => {
        try { const { data } = await authApi.me(); set({ user: data.user }); }
        catch { tokenStorage.clear(); set({ user: null, token: null }); }
      },

      clearError: () => set({ error: null }),
      setUser: (user) => set({ user }),
    }),
    {
      name: 'examedge-auth',
      storage: createJSONStorage(() => localStorage),
      partialize: (s) => ({ user: s.user, token: s.token }),
      onRehydrateStorage: () => (s) => { if (s?.token) tokenStorage.set(s.token); },
    }
  )
);

// ── Exam Store ──────────────────────────────────────────────
interface ExamState {
  exams: Exam[]; currentExam: Exam | null; isLoading: boolean; error: string | null;
  pagination: { page: number; lastPage: number; total: number };
  fetchExams: (p?: object) => Promise<void>;
  fetchExam: (id: string) => Promise<void>;
  createExam: (d: object) => Promise<Exam>;
  updateExam: (id: string, d: object) => Promise<void>;
  deleteExam: (id: string) => Promise<void>;
  publishExam: (id: string) => Promise<void>;
  duplicateExam: (id: string, d: object) => Promise<Exam>;
  clearError: () => void;
}

export const useExamStore = create<ExamState>()((set, get) => ({
  exams: [], currentExam: null, isLoading: false, error: null,
  pagination: { page: 1, lastPage: 1, total: 0 },

  fetchExams: async (params = {}) => {
    set({ isLoading: true, error: null });
    try {
      const { data } = await examApi.list(params);
      set({ exams: data.data, pagination: { page: data.current_page, lastPage: data.last_page, total: data.total }, isLoading: false });
    } catch (err: unknown) { set({ error: (err as {message?:string})?.message || 'Failed', isLoading: false }); }
  },

  fetchExam: async (id) => {
    set({ isLoading: true, error: null });
    try { const { data } = await examApi.show(id); set({ currentExam: data.exam, isLoading: false }); }
    catch (err: unknown) { set({ error: (err as {message?:string})?.message || 'Failed', isLoading: false }); }
  },

  createExam: async (formData) => {
    set({ isLoading: true, error: null });
    try {
      const { data } = await examApi.create(formData);
      set((s) => ({ exams: [data.exam, ...s.exams], isLoading: false }));
      return data.exam;
    } catch (err: unknown) { set({ error: (err as {message?:string})?.message || 'Failed', isLoading: false }); throw err; }
  },

  updateExam: async (id, formData) => {
    const { data } = await examApi.update(id, formData);
    set((s) => ({ exams: s.exams.map(e => e.id === id ? data.exam : e), currentExam: s.currentExam?.id === id ? data.exam : s.currentExam }));
  },

  deleteExam: async (id) => {
    await examApi.delete(id);
    set((s) => ({ exams: s.exams.filter(e => e.id !== id) }));
  },

  publishExam: async (id) => {
    const { data } = await examApi.publish(id);
    set((s) => ({ exams: s.exams.map(e => e.id === id ? data.exam : e), currentExam: s.currentExam?.id === id ? data.exam : s.currentExam }));
  },

  duplicateExam: async (id, formData) => {
    const { data } = await examApi.duplicate(id, formData);
    set((s) => ({ exams: [data.exam, ...s.exams] }));
    return data.exam;
  },

  clearError: () => set({ error: null }),
}));

// ── Session Store ───────────────────────────────────────────
interface SessionState {
  session: ExamSession | null; isLoading: boolean; isSubmitting: boolean;
  isSaving: boolean; error: string | null; autoSaveTimer: number | null;
  violations: number; tabSwitches: number;
  startSession: (examId: string) => Promise<ExamSession>;
  saveAnswer: (questionId: string, answer: unknown) => Promise<void>;
  flagQuestion: (questionId: string) => Promise<void>;
  submitSession: () => Promise<object>;
  recoverSession: (id: string) => Promise<void>;
  setCurrentQuestion: (index: number) => void;
  decrementTimer: () => void;
  startAutoSave: () => void;
  stopAutoSave: () => void;
  logViolation: (type: string, severity: string, desc?: string) => Promise<void>;
  clearSession: () => void;
}

export const useSessionStore = create<SessionState>()((set, get) => ({
  session: null, isLoading: false, isSubmitting: false, isSaving: false,
  error: null, autoSaveTimer: null, violations: 0, tabSwitches: 0,

  startSession: async (examId) => {
    set({ isLoading: true, error: null });
    try {
      const { data } = await sessionApi.start(examId);
      set({ session: data.session, isLoading: false });
      get().startAutoSave();
      return data.session;
    } catch (err: unknown) { set({ error: (err as {message?:string})?.message || 'Failed to start', isLoading: false }); throw err; }
  },

  saveAnswer: async (questionId, answer) => {
    const session = get().session;
    if (!session) return;
    set((s) => ({ session: s.session ? { ...s.session, answers: { ...s.session.answers, [questionId]: answer } } : null }));
    set({ isSaving: true });
    try { await sessionApi.saveAnswer(session.id, { question_id: questionId, answer, time_left: session.time_left, current_question: session.current_question }); }
    catch { } finally { set({ isSaving: false }); }
  },

  flagQuestion: async (questionId) => {
    const session = get().session; if (!session) return;
    const isFlagged = session.flagged.includes(questionId);
    set((s) => ({ session: s.session ? { ...s.session, flagged: isFlagged ? s.session!.flagged.filter(f => f !== questionId) : [...s.session!.flagged, questionId] } : null }));
    try { await sessionApi.flagQuestion(session.id, questionId); } catch { }
  },

  submitSession: async () => {
    const session = get().session; if (!session) throw new Error('No session');
    set({ isSubmitting: true, error: null }); get().stopAutoSave();
    try {
      const { data } = await sessionApi.submit(session.id, session.answers);
      set({ session: null, isSubmitting: false }); return data;
    } catch (err: unknown) { set({ error: (err as {message?:string})?.message || 'Submit failed', isSubmitting: false }); throw err; }
  },

  recoverSession: async (id) => {
    set({ isLoading: true });
    try { const { data } = await sessionApi.recover(id); set({ session: data.session, isLoading: false }); get().startAutoSave(); }
    catch (err: unknown) { set({ error: (err as {message?:string})?.message || 'Recovery failed', isLoading: false }); }
  },

  setCurrentQuestion: (index) => set((s) => ({ session: s.session ? { ...s.session, current_question: index } : null })),

  decrementTimer: () => set((s) => { if (!s.session) return s; return { session: { ...s.session, time_left: Math.max(0, s.session.time_left - 1) } }; }),

  startAutoSave: () => {
    const existing = get().autoSaveTimer; if (existing) clearInterval(existing);
    const timer = window.setInterval(async () => {
      const session = get().session; if (!session || session.status !== 'in_progress') return;
      try {
        await sessionApi.saveAnswer(session.id, { question_id: session.questions[session.current_question]?.id ?? '', answer: session.answers[session.questions[session.current_question]?.id ?? ''] ?? null, time_left: session.time_left, current_question: session.current_question });
      } catch { }
    }, 15_000);
    set({ autoSaveTimer: timer });
  },

  stopAutoSave: () => { const t = get().autoSaveTimer; if (t) clearInterval(t); set({ autoSaveTimer: null }); },

  logViolation: async (type, severity, desc) => {
    const session = get().session; if (!session) return;
    set((s) => ({ violations: s.violations + 1, tabSwitches: type === 'tab_switch' ? s.tabSwitches + 1 : s.tabSwitches }));
    try { await proctoringApi.logViolation({ session_id: session.id, violation_type: type, severity, description: desc }); } catch { }
  },

  clearSession: () => { get().stopAutoSave(); set({ session: null, violations: 0, tabSwitches: 0, error: null }); },
}));

// ── Notification Store ──────────────────────────────────────
interface NotifState {
  notifications: Notification[]; unreadCount: number; isLoading: boolean;
  fetchNotifications: () => Promise<void>;
  markRead: (id: string) => Promise<void>;
  markAllRead: () => Promise<void>;
  pollUnread: () => void;
}

export const useNotifStore = create<NotifState>()((set) => ({
  notifications: [], unreadCount: 0, isLoading: false,

  fetchNotifications: async () => {
    set({ isLoading: true });
    try {
      const { data } = await notificationApi.list({ per_page: 50 });
      const notifs = data.data ?? data;
      set({ notifications: notifs, unreadCount: notifs.filter((n: Notification) => !n.read).length, isLoading: false });
    } catch { set({ isLoading: false }); }
  },

  markRead: async (id) => {
    await notificationApi.markRead(id);
    set((s) => ({ notifications: s.notifications.map(n => n.id === id ? { ...n, read: true } : n), unreadCount: Math.max(0, s.unreadCount - 1) }));
  },

  markAllRead: async () => {
    await notificationApi.markAllRead();
    set((s) => ({ notifications: s.notifications.map(n => ({ ...n, read: true })), unreadCount: 0 }));
  },

  pollUnread: () => {
    setInterval(async () => {
      try { const { data } = await notificationApi.unreadCount(); set({ unreadCount: data.count }); } catch { }
    }, 30_000);
  },
}));
