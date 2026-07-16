import React, { useEffect, Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from './store/stores';
import { AppShell } from './components/AppShell';
import { LoginPage } from './pages/Pages';
import { ExamTakingPage, ResultPage } from './pages/ExamTaking';

const DashboardPage    = lazy(() => import('./pages/Pages').then(m => ({ default: m.DashboardPage })));
const ExamsPage        = lazy(() => import('./pages/Pages').then(m => ({ default: m.ExamsPage })));
const PlaceholderPage  = lazy(() => import('./pages/PlaceholderPages').then(m => ({ default: m.PlaceholderPage })));

function PageLoader() {
  return <div style={{display:'flex',alignItems:'center',justifyContent:'center',minHeight:400}}><div style={{width:32,height:32,border:'3px solid #e8f5f0',borderTopColor:'#1a7f5a',borderRadius:'50%',animation:'spin .6s linear infinite'}} /></div>;
}

function ProtectedRoute({ children, roles }: { children: React.ReactNode; roles?: string[] }) {
  const user = useAuthStore(s => s.user);
  if (!user) return <Navigate to="/login" replace />;
  if (roles && !roles.includes(user.role)) return <Navigate to="/dashboard" replace />;
  return <>{children}</>;
}

export default function App() {
  const { user, refreshUser } = useAuthStore();

  useEffect(() => {
    const token = localStorage.getItem('examedge_token');
    if (token && !user) refreshUser();
  }, []);

  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/cert/verify/:hash" element={<Suspense fallback={<PageLoader />}><PlaceholderPage title="Certificate Verification" /></Suspense>} />

        <Route path="/take/:examId" element={<ProtectedRoute><ExamTakingPage /></ProtectedRoute>} />
        <Route path="/result" element={<ProtectedRoute><ResultPage /></ProtectedRoute>} />

        <Route path="/" element={<ProtectedRoute><AppShell /></ProtectedRoute>}>
          <Route index element={<Navigate to="/dashboard" replace />} />
          <Route path="dashboard" element={<Suspense fallback={<PageLoader />}><DashboardPage /></Suspense>} />
          <Route path="exams" element={<Suspense fallback={<PageLoader />}><ExamsPage /></Suspense>} />
          <Route path="exams/:id" element={<Suspense fallback={<PageLoader />}><PlaceholderPage title="Exam Detail" /></Suspense>} />
          <Route path="exams/create" element={<ProtectedRoute roles={['instructor','admin']}><Suspense fallback={<PageLoader />}><PlaceholderPage title="Create Exam" /></Suspense></ProtectedRoute>} />
          <Route path="questions" element={<Suspense fallback={<PageLoader />}><PlaceholderPage title="Question Bank" /></Suspense>} />
          <Route path="grading" element={<ProtectedRoute roles={['instructor','admin']}><Suspense fallback={<PageLoader />}><PlaceholderPage title="AI Grading Queue" /></Suspense></ProtectedRoute>} />
          <Route path="grading/:sessionId" element={<ProtectedRoute roles={['instructor','admin']}><Suspense fallback={<PageLoader />}><PlaceholderPage title="Grade Session" /></Suspense></ProtectedRoute>} />
          <Route path="proctoring" element={<ProtectedRoute roles={['instructor','admin']}><Suspense fallback={<PageLoader />}><PlaceholderPage title="Proctoring Monitor" /></Suspense></ProtectedRoute>} />
          <Route path="analytics" element={<ProtectedRoute roles={['instructor','admin']}><Suspense fallback={<PageLoader />}><PlaceholderPage title="Analytics" /></Suspense></ProtectedRoute>} />
          <Route path="courses" element={<Suspense fallback={<PageLoader />}><PlaceholderPage title="Courses" /></Suspense>} />
          <Route path="certificates" element={<Suspense fallback={<PageLoader />}><PlaceholderPage title="Certificates" /></Suspense>} />
          <Route path="users" element={<ProtectedRoute roles={['admin']}><Suspense fallback={<PageLoader />}><PlaceholderPage title="User Management" /></Suspense></ProtectedRoute>} />
          <Route path="settings" element={<ProtectedRoute roles={['instructor','admin']}><Suspense fallback={<PageLoader />}><PlaceholderPage title="Settings" /></Suspense></ProtectedRoute>} />
          <Route path="*" element={<Suspense fallback={<PageLoader />}><PlaceholderPage title="Page Not Found" /></Suspense>} />
        </Route>

        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
