import { useEffect, useRef, useCallback, useState } from 'react';
import { useSessionStore, useAuthStore } from '../store/stores';

// ── Timer ──────────────────────────────────────────────────
export function useExamTimer({ onExpire, onWarning, warningAt = 300 }: { onExpire: () => void; onWarning: (s: number) => void; warningAt?: number }) {
  const { session, decrementTimer } = useSessionStore();
  const intervalRef = useRef<number | null>(null);
  const warnedRef = useRef(false); const expiredRef = useRef(false);

  useEffect(() => {
    if (!session || session.status !== 'in_progress') return;
    intervalRef.current = window.setInterval(() => {
      const t = useSessionStore.getState().session?.time_left ?? 0;
      if (t <= 0 && !expiredRef.current) { expiredRef.current = true; if (intervalRef.current) clearInterval(intervalRef.current); onExpire(); return; }
      if (t <= warningAt && !warnedRef.current && t > 0) { warnedRef.current = true; onWarning(t); }
      decrementTimer();
    }, 1000);
    return () => { if (intervalRef.current) clearInterval(intervalRef.current); };
  }, [session?.id]);

  const fmt = (s: number) => { const m = Math.floor(s/60); const sec = s%60; return `${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`; };
  return { timeLeft: session?.time_left ?? 0, formatted: fmt(session?.time_left ?? 0), isWarning: (session?.time_left ?? 0) <= warningAt && (session?.time_left ?? 0) > 0, isExpired: (session?.time_left ?? 0) <= 0, percentLeft: session ? Math.max(0, (session.time_left / (session.exam_duration * 60)) * 100) : 100 };
}

// ── Proctoring ─────────────────────────────────────────────
export function useProctoringEngine({ level, onViolation }: { level: string; onViolation: (t: string, s: string, d: string) => void }) {
  const { logViolation } = useSessionStore();
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);

  useEffect(() => {
    if (level === 'none') return;
    const handleVis = () => { if (document.hidden) { logViolation('tab_switch','medium','Tab switched'); onViolation('tab_switch','medium','Tab switched or window minimized'); } };
    document.addEventListener('visibilitychange', handleVis);
    return () => document.removeEventListener('visibilitychange', handleVis);
  }, [level]);

  useEffect(() => {
    if (level === 'none') return;
    const block = (e: ClipboardEvent) => { e.preventDefault(); logViolation('copy_attempt','medium','Copy blocked'); };
    const blockPaste = (e: ClipboardEvent) => e.preventDefault();
    document.addEventListener('copy', block, true); document.addEventListener('cut', block, true); document.addEventListener('paste', blockPaste, true);
    return () => { document.removeEventListener('copy', block, true); document.removeEventListener('cut', block, true); document.removeEventListener('paste', blockPaste, true); };
  }, [level]);

  useEffect(() => {
    if (level === 'none') return;
    const handleKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey||e.metaKey) && ['c','v','u','s','a'].includes(e.key.toLowerCase())) { e.preventDefault(); logViolation('keyboard_shortcut','low',`Ctrl+${e.key} blocked`); }
      if (e.key === 'F12') { e.preventDefault(); logViolation('keyboard_shortcut','medium','DevTools blocked'); }
    };
    document.addEventListener('keydown', handleKey, true);
    return () => document.removeEventListener('keydown', handleKey, true);
  }, [level]);

  useEffect(() => {
    if (level === 'none') return;
    const handleCtx = (e: MouseEvent) => { e.preventDefault(); logViolation('right_click','low','Right-click blocked'); };
    document.addEventListener('contextmenu', handleCtx, true);
    return () => document.removeEventListener('contextmenu', handleCtx, true);
  }, [level]);

  useEffect(() => {
    if (level !== 'full') return;
    const handleFS = () => { if (!document.fullscreenElement) { logViolation('fullscreen_exit','high','Exited fullscreen'); onViolation('fullscreen_exit','high','Exited fullscreen'); } };
    document.addEventListener('fullscreenchange', handleFS);
    document.documentElement.requestFullscreen?.().catch(() => {});
    return () => document.removeEventListener('fullscreenchange', handleFS);
  }, [level]);

  const startCamera = useCallback(async () => {
    if (level !== 'full') return;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 }, audio: false });
      streamRef.current = stream;
      if (videoRef.current) videoRef.current.srcObject = stream;
      return stream;
    } catch { console.warn('Camera denied'); }
  }, [level]);

  const stopCamera = useCallback(() => { streamRef.current?.getTracks().forEach(t => t.stop()); streamRef.current = null; }, []);

  const takeSnapshot = useCallback(() => {
    if (!videoRef.current) return null;
    const canvas = document.createElement('canvas');
    canvas.width = videoRef.current.videoWidth || 320; canvas.height = videoRef.current.videoHeight || 240;
    canvas.getContext('2d')?.drawImage(videoRef.current, 0, 0);
    return canvas.toDataURL('image/jpeg', 0.8);
  }, []);

  useEffect(() => () => stopCamera(), [stopCamera]);
  return { videoRef, startCamera, stopCamera, takeSnapshot };
}

// ── Debounce ───────────────────────────────────────────────
export function useDebounce<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState<T>(value);
  useEffect(() => { const h = setTimeout(() => setDebounced(value), delay); return () => clearTimeout(h); }, [value, delay]);
  return debounced;
}

// ── Permissions ────────────────────────────────────────────
export function usePermissions() {
  const user = useAuthStore(s => s.user);
  return {
    isAdmin: user?.role === 'admin',
    isInstructor: user?.role === 'instructor' || user?.role === 'admin',
    isStudent: user?.role === 'student',
    can: {
      createExam:     user?.role !== 'student',
      gradeEssays:    user?.role !== 'student',
      viewAllResults: user?.role !== 'student',
      manageUsers:    user?.role === 'admin',
      deleteExams:    user?.role === 'admin',
      viewAuditLog:   user?.role !== 'student',
      manageWebhooks: user?.role !== 'student',
    },
  };
}

// ── Local Storage ──────────────────────────────────────────
export function useLocalStorage<T>(key: string, initial: T) {
  const [val, setVal] = useState<T>(() => { try { const i = localStorage.getItem(key); return i ? JSON.parse(i) : initial; } catch { return initial; } });
  const setValue = (v: T | ((prev: T) => T)) => { try { const s = v instanceof Function ? v(val) : v; setVal(s); localStorage.setItem(key, JSON.stringify(s)); } catch { } };
  return [val, setValue] as const;
}
