import React, { useState, useEffect } from 'react';
import { Outlet, NavLink, useNavigate, useLocation } from 'react-router-dom';
import { useAuthStore, useNotifStore } from '../store/stores';

const NAV_SHARED = [
  { path: '/dashboard', label: 'Dashboard', icon: '🏠' },
  { path: '/exams', label: 'Exams', icon: '📋' },
  { path: '/courses', label: 'Courses', icon: '🎓' },
  { path: '/certificates', label: 'Certificates', icon: '⛓️' },
];
const NAV_INSTRUCTOR = [
  { path: '/questions', label: 'Question bank', icon: '📚' },
  { path: '/grading', label: 'AI grading', icon: '🤖' },
  { path: '/proctoring', label: 'Proctoring', icon: '🔍' },
  { path: '/analytics', label: 'Analytics', icon: '📊' },
  { path: '/settings', label: 'Settings', icon: '⚙️' },
];
const NAV_ADMIN = [{ path: '/users', label: 'Users', icon: '👥' }];
const THEME_KEY = 'examedge_theme';

export function AppShell() {
  const navigate = useNavigate();
  const location = useLocation();
  const { user, logout } = useAuthStore();
  const { unreadCount, pollUnread, fetchNotifications } = useNotifStore();
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [darkMode, setDarkMode] = useState(() => localStorage.getItem(THEME_KEY) === 'dark');

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', darkMode ? 'dark' : 'light');
    localStorage.setItem(THEME_KEY, darkMode ? 'dark' : 'light');
  }, [darkMode]);

  useEffect(() => { if (user) { fetchNotifications(); pollUnread(); } }, [user]);

  const handleLogout = async () => { await logout(); navigate('/login'); };
  const navItems = [...NAV_SHARED, ...(user?.role !== 'student' ? NAV_INSTRUCTOR : []), ...(user?.role === 'admin' ? NAV_ADMIN : [])];
  const initials = user?.name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() ?? '??';

  return (
    <div style={{ ...s.root, ...(darkMode ? s.rootDark : {}) }}>
      <aside style={{ ...s.sidebar, width: sidebarOpen ? 228 : 60 }}>
        <div style={s.brand}>
          <div style={s.brandMark}>E</div>
          {sidebarOpen && <div><div style={s.brandName}>ExamEdge Pro</div><div style={s.brandVer}>Production v2.0</div></div>}
        </div>
        <button style={s.collapseBtn} onClick={() => setSidebarOpen(!sidebarOpen)}>{sidebarOpen ? '◀' : '▶'}</button>
        <nav style={{ flex: 1, overflow: 'hidden auto', padding: '8px 0' }}>
          {navItems.map(item => (
            <NavLink key={item.path} to={item.path} style={({ isActive }) => ({ ...s.navItem, background: isActive ? '#1a7f5a' : 'transparent', color: isActive ? '#fff' : 'rgba(255,255,255,.65)', justifyContent: sidebarOpen ? 'flex-start' : 'center' })}>
              <span style={s.navIcon}>{item.icon}</span>
              {sidebarOpen && <span style={s.navLabel}>{item.label}</span>}
            </NavLink>
          ))}
        </nav>
        <div style={s.userSection}>
          <div style={s.avatar}>{initials}</div>
          {sidebarOpen && <div style={{ flex: 1, overflow: 'hidden' }}><div style={{ fontSize: 12, fontWeight: 600, color: '#fff', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{user?.name}</div><div style={{ fontSize: 10, color: 'rgba(255,255,255,.4)', textTransform: 'capitalize' }}>{user?.role}</div></div>}
        </div>
      </aside>
      <div style={s.mainArea}>
        <header style={s.header}>
          <div style={s.breadcrumb}>{getBreadcrumb(location.pathname)}</div>
          <div style={s.headerRight}>
            <button style={s.bellBtn} title="Notifications">🔔{unreadCount > 0 && <span style={s.bellBadge}>{unreadCount > 99 ? '99+' : unreadCount}</span>}</button>
            <button style={s.iconBtn} onClick={() => setDarkMode(!darkMode)} title="Toggle theme">{darkMode ? '☀️' : '🌙'}</button>
            <button style={s.iconBtn} onClick={handleLogout} title="Sign out">⏻</button>
          </div>
        </header>
        <main style={s.main}><Outlet /></main>
      </div>
    </div>
  );
}

function getBreadcrumb(path: string): React.ReactNode {
  const segments = path.split('/').filter(Boolean);
  const labels: Record<string,string> = { dashboard:'Dashboard', exams:'Exams', create:'Create', questions:'Question bank', grading:'AI grading', proctoring:'Proctoring', analytics:'Analytics', courses:'Courses', certificates:'Certificates', users:'Users', settings:'Settings' };
  return <span style={{fontSize:13,color:'#6b7280'}}>{segments.map((seg,i) => <span key={seg}>{i>0 && <span style={{margin:'0 5px',opacity:.4}}>/</span>}<span style={{color:i===segments.length-1?'var(--color-text-primary,#111)':'inherit',fontWeight:i===segments.length-1?500:400}}>{labels[seg]??seg}</span></span>)}</span>;
}

const s: Record<string, React.CSSProperties> = {
  root: {display:'flex',height:'100vh',overflow:'hidden',background:'var(--color-background-tertiary,#f8fafc)',color:'var(--color-text-primary,#111)'},
  rootDark: {background:'#0f1117',color:'#e5e7eb'},
  sidebar: {background:'#0f1923',display:'flex',flexDirection:'column',overflow:'hidden',flexShrink:0,transition:'width .2s ease',position:'relative'},
  brand: {padding:'14px 14px 10px',display:'flex',alignItems:'center',gap:10,borderBottom:'1px solid rgba(255,255,255,.07)',flexShrink:0},
  brandMark: {width:34,height:34,background:'#1a7f5a',borderRadius:9,display:'flex',alignItems:'center',justifyContent:'center',fontWeight:800,fontSize:15,color:'#fff',flexShrink:0},
  brandName: {fontSize:13,fontWeight:700,color:'#fff'},
  brandVer: {fontSize:10,color:'rgba(255,255,255,.35)',marginTop:1},
  collapseBtn: {position:'absolute',top:18,right:-12,width:24,height:24,borderRadius:'50%',background:'#1a7f5a',border:'none',color:'#fff',fontSize:10,cursor:'pointer',zIndex:10},
  navItem: {display:'flex',alignItems:'center',gap:10,padding:'8px 12px',margin:'1px 8px',borderRadius:8,cursor:'pointer',fontSize:13,fontWeight:500,textDecoration:'none',border:'none'},
  navIcon: {width:18,textAlign:'center',fontSize:15,flexShrink:0},
  navLabel: {flex:1,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'},
  userSection: {padding:'10px 12px',borderTop:'1px solid rgba(255,255,255,.07)',display:'flex',alignItems:'center',gap:9,flexShrink:0},
  avatar: {width:32,height:32,borderRadius:'50%',background:'#1a7f5a',display:'flex',alignItems:'center',justifyContent:'center',fontSize:12,fontWeight:700,color:'#fff',flexShrink:0},
  mainArea: {flex:1,display:'flex',flexDirection:'column',overflow:'hidden'},
  header: {background:'var(--color-background-primary,#fff)',borderBottom:'0.5px solid rgba(0,0,0,.1)',display:'flex',alignItems:'center',padding:'0 20px',gap:12,height:54,flexShrink:0,zIndex:10},
  breadcrumb: {flex:'0 0 auto'},
  headerRight: {marginLeft:'auto',display:'flex',alignItems:'center',gap:8},
  bellBtn: {position:'relative',width:34,height:34,border:'0.5px solid rgba(0,0,0,.15)',borderRadius:8,background:'#fff',cursor:'pointer',fontSize:15},
  bellBadge: {position:'absolute',top:3,right:3,width:16,height:16,borderRadius:'50%',background:'#c0392b',color:'#fff',fontSize:9,fontWeight:700,display:'flex',alignItems:'center',justifyContent:'center',border:'1.5px solid #fff'},
  iconBtn: {width:34,height:34,border:'0.5px solid rgba(0,0,0,.15)',borderRadius:8,background:'#fff',cursor:'pointer',fontSize:14},
  main: {flex:1,overflowY:'auto',overflowX:'hidden'},
};
