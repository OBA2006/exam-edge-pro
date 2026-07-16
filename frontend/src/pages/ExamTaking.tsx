import React, { useEffect, useState, useCallback, useRef } from 'react';
import { useParams, useNavigate, useLocation, Link } from 'react-router-dom';
import { useSessionStore } from '../store/stores';
import { useExamTimer, useProctoringEngine } from '../hooks/hooks';

export function ExamTakingPage() {
  const { examId } = useParams<{ examId: string }>();
  const navigate = useNavigate();
  const { session, startSession, saveAnswer, flagQuestion, submitSession, setCurrentQuestion, isLoading, isSubmitting } = useSessionStore();
  const [launched, setLaunched] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [toast, setToast] = useState<string|null>(null);
  const toastTimer = useRef<number|null>(null);

  const showToast = (msg: string) => { setToast(msg); if (toastTimer.current) clearTimeout(toastTimer.current); toastTimer.current = window.setTimeout(()=>setToast(null), 3500); };
  const { videoRef, startCamera } = useProctoringEngine({ level: session?.proctoring_level ?? 'basic', onViolation: (t,sev,d) => { if (sev==='high') showToast(`Violation: ${d}`); } });
  const { formatted, isWarning } = useExamTimer({ onExpire: () => handleAutoSubmit(), onWarning: (s) => showToast(`${Math.round(s/60)} minutes remaining!`), warningAt: 300 });

  useEffect(() => {
    if (!examId || launched) return;
    setLaunched(true);
    startSession(examId).then(s => { if (s.proctoring_level==='full') startCamera(); }).catch(() => navigate('/exams'));
  }, [examId]);

  const handleAutoSubmit = useCallback(async () => {
    showToast('Time is up! Submitting…');
    try { const r = await submitSession(); navigate('/result', { state: r }); } catch { navigate('/exams'); }
  }, [submitSession, navigate]);

  const handleSubmit = async () => {
    setShowConfirm(false);
    try { const r = await submitSession(); navigate('/result', { state: r }); }
    catch (e:any) { showToast(e?.message ?? 'Submit failed'); }
  };

  if (isLoading || !session) return <div style={st.fullCenter}><div style={st.spinner} /><p style={{color:'#6b7280',marginTop:14}}>Loading exam…</p></div>;

  const currentQ = session.questions[session.current_question];
  const answered = Object.keys(session.answers ?? {}).length;
  const progress = Math.round((answered / session.total_questions) * 100);

  return (
    <div style={st.examWrap}>
      {toast && <div style={st.toast}>{toast}</div>}
      {showConfirm && (
        <div style={st.overlay}><div style={st.modal}>
          <h3 style={{marginBottom:8}}>Submit exam?</h3>
          <p style={{color:'#6b7280',fontSize:14,marginBottom:20}}>You have answered {answered} of {session.total_questions} questions.</p>
          <div style={{display:'flex',gap:10,justifyContent:'flex-end'}}>
            <button style={st.btnSecondary} onClick={()=>setShowConfirm(false)}>Cancel</button>
            <button style={st.btnDanger} onClick={handleSubmit} disabled={isSubmitting}>{isSubmitting?'Submitting…':'Submit exam'}</button>
          </div>
        </div></div>
      )}
      <div style={st.topBar}>
        <div style={st.examTitle}>{session.exam_title}</div>
        <div style={st.progressSection}>
          <div style={{display:'flex',justifyContent:'space-between',fontSize:12,color:'#6b7280',marginBottom:5}}><span>Q{session.current_question+1} of {session.total_questions}</span><span>{answered} answered</span></div>
          <div style={st.progressTrack}><div style={{...st.progressFill,width:`${progress}%`}} /></div>
        </div>
        <div style={{...st.timer, ...(isWarning?st.timerWarn:{})}}>⏱ {formatted}</div>
        <div style={{display:'flex',gap:8}}>
          <button style={st.btnIcon} onClick={()=>flagQuestion(currentQ?.id??'')}>{session.flagged.includes(currentQ?.id??'')?'🚩':'⚐'}</button>
          <button style={st.btnNav} disabled={session.current_question===0} onClick={()=>setCurrentQuestion(session.current_question-1)}>◀</button>
          <button style={st.btnNav} disabled={session.current_question===session.total_questions-1} onClick={()=>setCurrentQuestion(session.current_question+1)}>▶</button>
          <button style={st.btnSubmit} onClick={()=>setShowConfirm(true)}>Submit</button>
        </div>
      </div>
      <div style={st.body}>
        {currentQ ? <QuestionRenderer question={currentQ as any} answer={session.answers[currentQ.id]} onAnswer={(ans)=>saveAnswer(currentQ.id, ans)} /> : <div style={{textAlign:'center',color:'#6b7280',marginTop:40}}>No question</div>}
        <div style={st.navPanel}>
          <div style={{fontSize:11,fontWeight:600,color:'#6b7280',marginBottom:10,textTransform:'uppercase'}}>Navigator</div>
          <div style={st.qGrid}>
            {session.questions.map((q,i) => {
              const isAnswered = session.answers[q.id]!==undefined && session.answers[q.id]!==null && session.answers[q.id]!=='';
              const isFlagged = session.flagged.includes(q.id);
              const isCurrent = i===session.current_question;
              return <button key={q.id} style={{...st.qDot, background:isCurrent?'#1865f2':isFlagged?'#fff8e7':isAnswered?'#1a7f5a':'#fff', color:isCurrent?'#fff':isFlagged?'#c97c0a':isAnswered?'#fff':'#6b7280', borderColor:isCurrent?'#1865f2':isFlagged?'#c97c0a':isAnswered?'#1a7f5a':'rgba(0,0,0,.15)'}} onClick={()=>setCurrentQuestion(i)}>{i+1}</button>;
            })}
          </div>
          {session.proctoring_level==='full' && (
            <div style={{marginTop:16}}>
              <div style={{fontSize:11,fontWeight:600,color:'#6b7280',marginBottom:6}}>Camera</div>
              <video ref={videoRef} autoPlay playsInline muted style={{width:'100%',borderRadius:8,background:'#0d1117',maxHeight:120}} />
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function QuestionRenderer({question,answer,onAnswer}:{question:any;answer:unknown;onAnswer:(a:unknown)=>void}) {
  const diffColor: Record<string,string> = {easy:'#1a7f5a',medium:'#c97c0a',hard:'#c0392b'};
  return (
    <div style={st.qCard}>
      <div style={{display:'flex',gap:8,marginBottom:14,flexWrap:'wrap'}}>
        <span style={st.qTypePill}>{question.type.toUpperCase()}</span>
        <span style={{fontSize:11,fontWeight:500,color:diffColor[question.difficulty]??'#6b7280'}}>● {question.difficulty}</span>
        <span style={{fontSize:11,color:'#6b7280'}}>{question.points} pts</span>
      </div>
      <div style={st.qText}>{question.text}</div>
      {question.type==='mcq' && question.options && (
        <div style={{display:'flex',flexDirection:'column',gap:8}}>
          {question.options.map((opt:any,i:number) => (
            <button key={i} style={{...st.optionBtn, borderColor: answer===i?'#1a7f5a':'rgba(0,0,0,.15)', background: answer===i?'#e8f5f0':'#fff'}} onClick={()=>onAnswer(i)}>
              <span style={{...st.optRing, borderColor: answer===i?'#1a7f5a':'rgba(0,0,0,.2)', background: answer===i?'#1a7f5a':'transparent'}}>{answer===i && <span style={st.optDot} />}</span>
              <span style={{fontSize:14}}><b>{String.fromCharCode(65+i)}.</b> {opt.text}</span>
            </button>
          ))}
        </div>
      )}
      {question.type==='truefalse' && (
        <div style={{display:'flex',gap:10}}>
          {['true','false'].map(val => (
            <button key={val} style={{...st.optionBtn,flex:1,justifyContent:'center',borderColor:answer===val?'#1a7f5a':'rgba(0,0,0,.15)',background:answer===val?'#e8f5f0':'#fff'}} onClick={()=>onAnswer(val)}>{val==='true'?'✅ True':'❌ False'}</button>
          ))}
        </div>
      )}
      {question.type==='essay' && (
        <div>
          <textarea style={st.essayArea} rows={8} placeholder="Write your answer here…" value={(answer as string)??''} onChange={e=>onAnswer(e.target.value)} />
          <div style={{fontSize:12,color:'#6b7280',marginTop:6}}>AI will evaluate semantic similarity, keyword coverage, and rubric criteria.</div>
        </div>
      )}
      {question.type==='coding' && (
        <div>
          <textarea style={{...st.essayArea,fontFamily:'monospace',fontSize:13,background:'#0d1117',color:'#c9d1d9',minHeight:180}} rows={10} placeholder="# Write your code here…" value={(answer as string)??''} onChange={e=>onAnswer(e.target.value)} />
          <div style={{fontSize:12,color:'#6b7280',marginTop:6}}>Claude will evaluate correctness, time complexity, and code quality.</div>
        </div>
      )}
      {question.type==='fillin' && <input style={st.fillinInput} type="text" placeholder="Type your answer…" value={(answer as string)??''} onChange={e=>onAnswer(e.target.value)} />}
    </div>
  );
}

export function ResultPage() {
  const { state } = useLocation();
  const navigate = useNavigate();
  const result = state as { final_score?:number; auto_score?:number; total?:number; has_essays?:boolean; passed?:boolean } | null;
  if (!result) { navigate('/exams'); return null; }
  const score = result.final_score; const passed = result.passed; const pending = result.has_essays && score==null;
  const scoreColor = pending?'#c97c0a':(passed?'#1a7f5a':'#c0392b');
  return (
    <div style={{minHeight:'100vh',display:'flex',alignItems:'center',justifyContent:'center',background:'#f8fafc',padding:20}}>
      <div style={{background:'#fff',borderRadius:16,padding:'40px 36px',maxWidth:480,width:'100%',textAlign:'center',boxShadow:'0 4px 32px rgba(0,0,0,.08)'}}>
        <h2 style={{fontSize:24,fontWeight:700,marginBottom:8}}>{pending?'Exam submitted!':(passed?'Congratulations!':'Keep going!')}</h2>
        <p style={{fontSize:14,color:'#6b7280',marginBottom:28}}>{pending?'Your essay answers are being reviewed by Claude AI.':(passed?'You passed! Well done.':'You did not pass this time.')}</p>
        <div style={{background:'#f8fafc',borderRadius:12,padding:'24px 16px',marginBottom:28}}>
          <div style={{fontSize:52,fontWeight:800,color:scoreColor,marginBottom:4}}>{pending?'—':`${score}%`}</div>
          <div style={{fontSize:14,color:'#6b7280'}}>{pending?'AI grading in progress':`${result.auto_score??score} / ${result.total??100} points`}</div>
        </div>
        <div style={{display:'flex',gap:10,justifyContent:'center',flexWrap:'wrap'}}>
          <Link to="/exams" style={{padding:'10px 20px',borderRadius:8,fontSize:14,fontWeight:600,background:'#1a7f5a',color:'#fff',textDecoration:'none'}}>← Back to exams</Link>
          <Link to="/dashboard" style={{padding:'10px 20px',borderRadius:8,fontSize:14,fontWeight:600,background:'#fff',color:'#374151',border:'0.5px solid rgba(0,0,0,.2)',textDecoration:'none'}}>Dashboard</Link>
        </div>
      </div>
    </div>
  );
}

const st: Record<string, React.CSSProperties> = {
  examWrap:{display:'flex',flexDirection:'column',height:'100vh',background:'#f8fafc',overflow:'hidden',userSelect:'none'},
  topBar:{background:'#fff',borderBottom:'0.5px solid rgba(0,0,0,.1)',padding:'10px 20px',display:'flex',alignItems:'center',gap:16,flexShrink:0,flexWrap:'wrap'},
  examTitle:{fontWeight:700,fontSize:14,maxWidth:200,overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'},
  progressSection:{flex:'1 1 200px',minWidth:120},
  progressTrack:{height:8,background:'#f0f2f5',borderRadius:4,overflow:'hidden'},
  progressFill:{height:'100%',background:'#1a7f5a',borderRadius:4,transition:'width .4s'},
  timer:{fontSize:20,fontWeight:700,padding:'8px 16px',borderRadius:8,background:'#fce8e8',color:'#c0392b',fontVariantNumeric:'tabular-nums'},
  timerWarn:{animation:'pulse .8s ease-in-out infinite'},
  btnIcon:{width:34,height:34,borderRadius:8,border:'0.5px solid rgba(0,0,0,.15)',background:'#fff',cursor:'pointer',fontSize:16},
  btnNav:{padding:'6px 12px',borderRadius:8,border:'0.5px solid rgba(0,0,0,.15)',background:'#fff',cursor:'pointer',fontSize:14},
  btnSubmit:{padding:'6px 16px',borderRadius:8,border:'none',background:'#c0392b',color:'#fff',cursor:'pointer',fontSize:13,fontWeight:600},
  body:{display:'flex',flex:1,overflow:'hidden'},
  qCard:{flex:1,overflowY:'auto',padding:24},
  qTypePill:{fontSize:11,fontWeight:700,padding:'3px 9px',borderRadius:20,background:'#e8f0fe',color:'#1865f2'},
  qText:{fontSize:16,fontWeight:600,lineHeight:1.6,marginBottom:20},
  optionBtn:{display:'flex',alignItems:'flex-start',gap:12,padding:'12px 14px',border:'1.5px solid',borderRadius:10,cursor:'pointer',background:'#fff',textAlign:'left'},
  optRing:{width:20,height:20,borderRadius:'50%',border:'2px solid',flexShrink:0,marginTop:1,display:'flex',alignItems:'center',justifyContent:'center'},
  optDot:{width:8,height:8,borderRadius:'50%',background:'#fff'},
  essayArea:{width:'100%',padding:'12px 14px',border:'0.5px solid rgba(0,0,0,.2)',borderRadius:10,fontSize:14,lineHeight:1.6,minHeight:120,boxSizing:'border-box'},
  fillinInput:{width:'100%',padding:'10px 14px',border:'0.5px solid rgba(0,0,0,.2)',borderRadius:10,fontSize:15,boxSizing:'border-box'},
  navPanel:{width:220,flexShrink:0,borderLeft:'0.5px solid rgba(0,0,0,.1)',background:'#fff',padding:14,overflowY:'auto'},
  qGrid:{display:'flex',flexWrap:'wrap',gap:5},
  qDot:{width:30,height:30,borderRadius:7,border:'1.5px solid',display:'flex',alignItems:'center',justifyContent:'center',fontSize:12,fontWeight:600,cursor:'pointer'},
  fullCenter:{display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',minHeight:'100vh'},
  spinner:{width:36,height:36,border:'3px solid #e8f5f0',borderTopColor:'#1a7f5a',borderRadius:'50%',animation:'spin .6s linear infinite'},
  toast:{position:'fixed',bottom:20,right:20,background:'#0f1923',color:'#fff',padding:'12px 18px',borderRadius:10,fontSize:13,fontWeight:500,zIndex:9999,borderLeft:'4px solid #1a7f5a',maxWidth:320},
  overlay:{position:'fixed',inset:0,background:'rgba(0,0,0,.45)',zIndex:1000,display:'flex',alignItems:'center',justifyContent:'center'},
  modal:{background:'#fff',borderRadius:14,padding:28,width:'100%',maxWidth:420},
  btnSecondary:{padding:'8px 18px',background:'#fff',color:'#374151',border:'0.5px solid rgba(0,0,0,.2)',borderRadius:8,fontSize:14,cursor:'pointer'},
  btnDanger:{padding:'8px 18px',background:'#c0392b',color:'#fff',border:'none',borderRadius:8,fontSize:14,fontWeight:600,cursor:'pointer'},
};
