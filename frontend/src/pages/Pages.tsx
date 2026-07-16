import React, { useState, useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuthStore, useExamStore } from '../store/stores';
import { examApi, analyticsApi, gradingApi, notificationApi } from '../utils/api';
import { useDebounce, usePermissions } from '../hooks/hooks';

// ── Login Page ─────────────────────────────────────────────
export function LoginPage() {
  const navigate = useNavigate();
  const { login, register, error, isLoading, twoFactorPending, verifyTwoFactor, clearError } = useAuthStore();
  const [mode, setMode] = useState<'login'|'register'>('login');
  const [tfaCode, setTfaCode] = useState('');
  const [form, setForm] = useState({ name:'', email:'', password:'', password_confirmation:'', role:'student', institution:'' });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); clearError();
    try {
      if (mode === 'login') { await login(form.email, form.password); if (!useAuthStore.getState().twoFactorPending) navigate('/dashboard'); }
      else { await register(form); navigate('/dashboard'); }
    } catch { }
  };

  const handleTFA = async (e: React.FormEvent) => {
    e.preventDefault();
    try { await verifyTwoFactor(tfaCode); navigate('/dashboard'); } catch { }
  };

  if (twoFactorPending) {
    return (
      <div style={s.container}><div style={s.card}>
        <div style={s.logo}>ExamEdge Pro</div>
        <h2 style={s.title}>Two-factor authentication</h2>
        <p style={s.sub}>Enter the 6-digit code from your authenticator app.</p>
        {error && <div style={s.errorBox}>{error}</div>}
        <form onSubmit={handleTFA}>
          <input style={{...s.input, textAlign:'center', letterSpacing:8, fontSize:22}} maxLength={6} placeholder="000000" value={tfaCode} onChange={e=>setTfaCode(e.target.value.replace(/\D/g,''))} autoFocus />
          <button style={s.btn} type="submit" disabled={isLoading || tfaCode.length!==6}>{isLoading?'Verifying…':'Verify'}</button>
        </form>
      </div></div>
    );
  }

  return (
    <div style={s.container}><div style={s.card}>
      <div style={s.logo}>ExamEdge Pro</div>
      <h2 style={s.title}>{mode==='login'?'Welcome back':'Create account'}</h2>
      <p style={s.sub}>{mode==='login'?'Sign in to your account.':'Join as a student or instructor.'}</p>
      {error && <div style={s.errorBox}>{error}</div>}
      <form onSubmit={handleSubmit}>
        {mode==='register' && (<>
          <label style={s.label}>Full name</label>
          <input style={s.input} value={form.name} onChange={e=>setForm({...form,name:e.target.value})} required />
          <label style={s.label}>Role</label>
          <select style={s.input} value={form.role} onChange={e=>setForm({...form,role:e.target.value})}>
            <option value="student">Student</option><option value="instructor">Instructor</option><option value="admin">Administrator</option>
          </select>
        </>)}
        <label style={s.label}>Email address</label>
        <input style={s.input} type="email" value={form.email} onChange={e=>setForm({...form,email:e.target.value})} required />
        <label style={s.label}>Password</label>
        <input style={s.input} type="password" value={form.password} onChange={e=>setForm({...form,password:e.target.value})} required />
        {mode==='register' && (<>
          <label style={s.label}>Confirm password</label>
          <input style={s.input} type="password" value={form.password_confirmation} onChange={e=>setForm({...form,password_confirmation:e.target.value})} required />
        </>)}
        <button style={s.btn} type="submit" disabled={isLoading}>{isLoading?'Please wait…':mode==='login'?'Sign in':'Create account'}</button>
      </form>
      <div style={s.switchRow}>
        {mode==='login' ? (<><span style={{color:'#6b7280',fontSize:13}}>No account? </span><button style={s.linkBtn} onClick={()=>{setMode('register');clearError();}}>Register</button></>)
          : (<><span style={{color:'#6b7280',fontSize:13}}>Have an account? </span><button style={s.linkBtn} onClick={()=>{setMode('login');clearError();}}>Sign in</button></>)}
      </div>
    </div></div>
  );
}

// ── Dashboard ──────────────────────────────────────────────
export function DashboardPage() {
  const user = useAuthStore(st => st.user);
  const [stats, setStats] = useState<any>(null);
  const [exams, setExams] = useState<any[]>([]);
  const [queue, setQueue] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      analyticsApi.platformStats(),
      examApi.list({ per_page: 5 }),
      user?.role !== 'student' ? gradingApi.queue({ per_page: 3 }) : Promise.resolve({ data: { data: [] } }),
    ]).then(([statsRes, examsRes, queueRes]) => {
      setStats(statsRes.data); setExams(examsRes.data.data ?? []); setQueue((queueRes as any).data.data ?? []);
    }).finally(() => setLoading(false));
  }, [user]);

  if (loading) return <PageLoader />;
  const greeting = () => { const h = new Date().getHours(); return h<12?'morning':h<17?'afternoon':'evening'; };

  return (
    <div style={pg.wrap}>
      <div style={pg.banner}>
        <div>
          <h2 style={{fontSize:22,fontWeight:600,marginBottom:4}}>Good {greeting()}, {user?.name?.split(' ')[0]}</h2>
          <p style={{fontSize:14,opacity:.8}}>{user?.role==='student' ? 'Here are your available exams.' : `${stats?.pending_ai ?? 0} pending AI grading · ${stats?.submissions ?? 0} total submissions`}</p>
        </div>
      </div>
      <div style={pg.kpiGrid}>
        <KpiCard icon="📋" value={stats?.exams??0} label="Total exams" color="#1a7f5a" />
        <KpiCard icon="📝" value={stats?.questions??0} label="Questions banked" color="#1865f2" />
        <KpiCard icon="🤖" value={stats?.submissions??0} label="Submissions" color="#c97c0a" />
        <KpiCard icon="🎓" value={stats?.certificates??0} label="Certificates" color="#6c3fc7" />
      </div>
      <div style={pg.grid2}>
        <div style={pg.card}>
          <div style={pg.cardHeader}><span style={pg.cardTitle}>Recent exams</span><Link to="/exams" style={pg.link}>View all →</Link></div>
          {exams.length===0 ? <EmptyState icon="📋" text="No exams yet" /> : exams.map((e:any) => (
            <div key={e.id} style={pg.listRow}><div style={{flex:1}}><div style={pg.rowTitle}>{e.title}</div><div style={pg.rowSub}>{e.questions_count??0} questions · {e.total_submissions??0} submissions</div></div><StatusPill status={e.status} /></div>
          ))}
        </div>
        {user?.role !== 'student' && (
          <div style={pg.card}>
            <div style={pg.cardHeader}><span style={pg.cardTitle}>AI grading queue</span><Link to="/grading" style={pg.link}>Grade now →</Link></div>
            {queue.length===0 ? <EmptyState icon="✅" text="Queue empty — all caught up!" /> : queue.map((item:any) => (
              <div key={item.id} style={pg.listRow}><div style={{flex:1}}><div style={pg.rowTitle}>{item.exam?.title}</div><div style={pg.rowSub}>{item.user?.name}</div></div></div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// ── Exams List ─────────────────────────────────────────────
export function ExamsPage() {
  const navigate = useNavigate();
  const { can } = usePermissions();
  const { exams, fetchExams, deleteExam, publishExam, isLoading } = useExamStore();
  const [search, setSearch] = useState('');
  const dSearch = useDebounce(search, 300);

  useEffect(() => { fetchExams({ search: dSearch, per_page: 20 }); }, [dSearch]);

  const handleDelete = async (id: string, title: string) => { if (!confirm(`Delete "${title}"?`)) return; await deleteExam(id); };
  const handlePublish = async (id: string) => { try { await publishExam(id); } catch (e:any) { alert(e?.message ?? 'Publish failed'); } };

  return (
    <div style={pg.wrap}>
      <div style={{display:'flex',alignItems:'center',justifyContent:'space-between',marginBottom:20}}>
        <div><h1 style={pg.pageTitle}>Exams</h1><p style={pg.pageSub}>Create and manage examinations</p></div>
        {can.createExam && <button style={pg.btnPrimary} onClick={()=>navigate('/exams/create')}>+ Create exam</button>}
      </div>
      <input style={{...pg.searchInput,maxWidth:260,marginBottom:16}} placeholder="Search exams…" value={search} onChange={e=>setSearch(e.target.value)} />
      {isLoading ? <PageLoader /> : exams.length===0 ? <EmptyState icon="📋" text="No exams found." /> : (
        <div style={pg.card}>
          <table style={pg.table}>
            <thead><tr>{['Title','Questions','Duration','Pass mark','Submissions','Status','Actions'].map(h=><th key={h} style={pg.th}>{h}</th>)}</tr></thead>
            <tbody>
              {exams.map((exam:any) => (
                <tr key={exam.id}>
                  <td style={pg.td}><Link to={`/exams/${exam.id}`} style={{fontWeight:600,color:'#1865f2',textDecoration:'none'}}>{exam.title}</Link></td>
                  <td style={pg.td}>{exam.questions_count??0}</td>
                  <td style={pg.td}>{exam.duration}min</td>
                  <td style={pg.td}>{exam.pass_mark}%</td>
                  <td style={pg.td}>{exam.total_submissions}</td>
                  <td style={pg.td}><StatusPill status={exam.status} /></td>
                  <td style={pg.td}>
                    <div style={{display:'flex',gap:5}}>
                      <Link to={`/exams/${exam.id}`} style={pg.btnSmall}>Edit</Link>
                      {exam.status==='draft' && can.createExam && <button style={{...pg.btnSmall,background:'#e8f5f0',color:'#1a7f5a',border:'none',cursor:'pointer'}} onClick={()=>handlePublish(exam.id)}>Publish</button>}
                      <Link to={`/take/${exam.id}`} style={{...pg.btnSmall,background:'#e8f0fe',color:'#1865f2',border:'none'}}>Take</Link>
                      {can.deleteExams && <button style={{...pg.btnSmall,background:'#fce8e8',color:'#c0392b',border:'none',cursor:'pointer'}} onClick={()=>handleDelete(exam.id,exam.title)}>Delete</button>}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

// ── Shared styles + components ──────────────────────────────
export const pg: Record<string, React.CSSProperties> = {
  wrap: {padding:'20px 24px',maxWidth:1200},
  banner: {background:'linear-gradient(135deg,#1a7f5a 0%,#0d5c3f 100%)',borderRadius:14,padding:'22px 24px',color:'#fff',marginBottom:20},
  kpiGrid: {display:'grid',gridTemplateColumns:'repeat(4,1fr)',gap:12,marginBottom:20},
  grid2: {display:'grid',gridTemplateColumns:'1fr 1fr',gap:16},
  card: {background:'var(--color-background-primary,#fff)',border:'0.5px solid var(--color-border-tertiary,rgba(0,0,0,.1))',borderRadius:12,overflow:'hidden',marginBottom:14},
  cardHeader: {padding:'12px 16px',display:'flex',alignItems:'center',justifyContent:'space-between',borderBottom:'0.5px solid rgba(0,0,0,.1)'},
  cardTitle: {fontSize:14,fontWeight:500},
  listRow: {display:'flex',alignItems:'center',gap:10,padding:'10px 16px',borderBottom:'0.5px solid rgba(0,0,0,.08)'},
  rowTitle: {fontSize:13,fontWeight:600},
  rowSub: {fontSize:12,color:'#6b7280',marginTop:2},
  link: {fontSize:13,color:'#1865f2',textDecoration:'none',fontWeight:500},
  pageTitle: {fontSize:22,fontWeight:700,marginBottom:4},
  pageSub: {fontSize:13,color:'#6b7280'},
  btnPrimary: {padding:'8px 16px',background:'#1a7f5a',color:'#fff',border:'none',borderRadius:8,fontSize:14,fontWeight:600,cursor:'pointer'},
  btnSmall: {padding:'5px 10px',background:'#fff',color:'#374151',border:'0.5px solid rgba(0,0,0,.2)',borderRadius:7,fontSize:12,fontWeight:500,cursor:'pointer',textDecoration:'none',display:'inline-flex',alignItems:'center'},
  searchInput: {padding:'8px 12px',border:'0.5px solid rgba(0,0,0,.2)',borderRadius:8,fontSize:13,outline:'none',width:'100%'},
  table: {width:'100%',borderCollapse:'collapse'},
  th: {fontSize:11,fontWeight:500,color:'#6b7280',textAlign:'left',padding:'8px 12px',borderBottom:'0.5px solid rgba(0,0,0,.1)'},
  td: {padding:'9px 12px',borderBottom:'0.5px solid rgba(0,0,0,.08)'},
};

export function KpiCard({icon,value,label,color}:{icon:string;value:number|string;label:string;color:string}) {
  return <div style={{background:'var(--color-background-primary,#fff)',border:'0.5px solid rgba(0,0,0,.1)',borderRadius:12,padding:16}}>
    <div style={{fontSize:22,marginBottom:8}}>{icon}</div>
    <div style={{fontSize:28,fontWeight:700,color,marginBottom:2}}>{value}</div>
    <div style={{fontSize:12,color:'#6b7280'}}>{label}</div>
  </div>;
}

export function StatusPill({status}:{status:string}) {
  const map: Record<string,{bg:string;color:string}> = {
    draft:{bg:'#f1f5f9',color:'#6b7280'}, published:{bg:'#e8f5f0',color:'#0d4f36'}, active:{bg:'#e8f5f0',color:'#0d4f36'},
    closed:{bg:'#fce8e8',color:'#7a1a12'}, archived:{bg:'#f1f5f9',color:'#6b7280'}, grading:{bg:'#f3f0ff',color:'#3d1f8f'},
    results:{bg:'#e8f0fe',color:'#0d3a8f'}, pending:{bg:'#fff8e7',color:'#7a4f00'}, graded:{bg:'#e8f5f0',color:'#0d4f36'},
  };
  const st = map[status] ?? {bg:'#f1f5f9',color:'#6b7280'};
  return <span style={{fontSize:11,fontWeight:500,padding:'2px 8px',borderRadius:20,background:st.bg,color:st.color,whiteSpace:'nowrap'}}>{status.replace('_',' ')}</span>;
}

export function EmptyState({icon,text}:{icon:string;text:string}) {
  return <div style={{textAlign:'center',padding:'32px 20px',color:'#6b7280'}}><div style={{fontSize:32,marginBottom:8,opacity:.5}}>{icon}</div><div style={{fontSize:13}}>{text}</div></div>;
}

export function PageLoader() {
  return <div style={{display:'flex',justifyContent:'center',alignItems:'center',minHeight:200}}><div style={{width:32,height:32,border:'3px solid #e8f5f0',borderTopColor:'#1a7f5a',borderRadius:'50%',animation:'spin .6s linear infinite'}} /></div>;
}

const s: Record<string, React.CSSProperties> = {
  container: {minHeight:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'#f8fafc',padding:20},
  card: {background:'#fff',borderRadius:16,padding:'36px 32px',width:'100%',maxWidth:420,boxShadow:'0 4px 32px rgba(0,0,0,.08)'},
  logo: {fontSize:24,fontWeight:700,textAlign:'center',marginBottom:4,color:'#1a7f5a'},
  title: {fontSize:22,fontWeight:600,textAlign:'center',marginBottom:6},
  sub: {fontSize:14,color:'#6b7280',textAlign:'center',marginBottom:24},
  label: {display:'block',fontSize:12,fontWeight:500,color:'#6b7280',marginBottom:5,textTransform:'uppercase'},
  input: {width:'100%',padding:'9px 12px',border:'0.5px solid rgba(0,0,0,.2)',borderRadius:8,fontSize:14,marginBottom:14,boxSizing:'border-box',outline:'none'},
  btn: {width:'100%',padding:'10px 0',background:'#1a7f5a',color:'#fff',border:'none',borderRadius:8,fontSize:14,fontWeight:600,cursor:'pointer',marginBottom:16},
  errorBox: {background:'#fce8e8',border:'1px solid rgba(192,57,43,.3)',borderRadius:8,padding:'10px 13px',fontSize:13,color:'#7a1a12',marginBottom:14},
  switchRow: {display:'flex',alignItems:'center',justifyContent:'center',gap:4},
  linkBtn: {background:'none',border:'none',color:'#1865f2',fontSize:13,cursor:'pointer',textDecoration:'underline'},
};
