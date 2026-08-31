
import React, { useState, useEffect } from 'react';
import { UserRole, Employee, AttendanceRecord, AppState, AttendanceStatus } from './types.ts';
import { DEFAULT_SETTINGS, SQL_SCHEMA, PHP_DB_CONN } from './constants.ts';
import { calculateStatus, calculateOvertime, calculateTotalHours, formatTime } from './utils.ts';
import Dashboard from './components/Dashboard.tsx';
import Admin from './components/Admin.tsx';
import PublicBoard from './components/PublicBoard.tsx';
import Sidebar from './components/Sidebar.tsx';
import Reports from './components/Reports.tsx';

const App: React.FC = () => {
  const [isSidebarOpen, setSidebarOpen] = useState(false);
  const [state, setState] = useState<AppState>(() => {
    const admin: Employee = { 
        id: '1', name: 'System Admin', email: 'admin@office.com', 
        role: UserRole.ADMIN, hourlyRate: 50, avatar: 'https://picsum.photos/seed/admin/200' 
    };
    const user: Employee = { 
        id: '2', name: 'John Doe', email: 'john@office.com', 
        role: UserRole.USER, hourlyRate: 25, avatar: 'https://picsum.photos/seed/john/200' 
    };

    const initialState: AppState = {
      currentUser: user,
      employees: [admin, user],
      attendance: [],
      settings: DEFAULT_SETTINGS,
      currentIp: '192.168.1.100' 
    };

    const saved = localStorage.getItem('attendance_state');
    if (saved) {
      try {
        const parsed = JSON.parse(saved);
        return { 
          ...initialState, 
          ...parsed, 
          employees: initialState.employees,
          currentUser: parsed.currentUser || user 
        };
      } catch (e) {
        return initialState;
      }
    }
    return initialState;
  });

  const [activeTab, setActiveTab] = useState<'dashboard' | 'board' | 'admin' | 'sql' | 'reports'>('dashboard');

  useEffect(() => {
    localStorage.setItem('attendance_state', JSON.stringify(state));
  }, [state]);

  const toggleRole = () => {
    const admin = state.employees.find(e => e.role === UserRole.ADMIN);
    const user = state.employees.find(e => e.role === UserRole.USER);
    setState(prev => ({
      ...prev,
      currentUser: prev.currentUser?.role === UserRole.ADMIN ? user! : admin!
    }));
  };

  const toggleIp = () => {
    setState(prev => ({
      ...prev,
      currentIp: prev.currentIp === state.settings.officeIpAddress ? '1.2.3.4' : state.settings.officeIpAddress
    }));
  };

  const signIn = () => {
    if (state.currentIp !== state.settings.officeIpAddress) {
      alert("⚠️ ACCESS DENIED: Please connect to the Office WiFi (" + state.settings.officeIpAddress + ") to Sign In.");
      return;
    }
    if (!state.currentUser) return;

    const today = new Date().toISOString().split('T')[0];
    const existing = state.attendance.find(r => r.employeeId === state.currentUser!.id && r.date === today);
    
    if (existing) {
      alert("Already signed in for today.");
      return;
    }

    const now = new Date();
    const timeIn = formatTime(now);
    const newRecord: AttendanceRecord = {
      id: Math.random().toString(36).substr(2, 9),
      employeeId: state.currentUser.id,
      date: today,
      timeIn,
      timeOut: null,
      status: calculateStatus(timeIn, state.settings.startTime, state.settings.gracePeriodMinutes),
      overtimeHours: 0,
      totalHours: 0
    };

    setState(prev => ({ ...prev, attendance: [...prev.attendance, newRecord] }));
  };

  const signOut = () => {
    if (state.currentIp !== state.settings.officeIpAddress) {
      alert("⚠️ ACCESS DENIED: You must be on the Office WiFi to Sign Out.");
      return;
    }
    if (!state.currentUser) return;

    const today = new Date().toISOString().split('T')[0];
    const recordIndex = state.attendance.findIndex(r => r.employeeId === state.currentUser!.id && r.date === today && r.timeOut === null);

    if (recordIndex === -1) {
      alert("No active session found.");
      return;
    }

    const now = new Date();
    const timeOut = formatTime(now);
    const updatedAttendance = [...state.attendance];
    const record = updatedAttendance[recordIndex];
    
    record.timeOut = timeOut;
    record.overtimeHours = calculateOvertime(timeOut, state.settings.endTime);
    record.totalHours = calculateTotalHours(record.timeIn, timeOut);

    setState(prev => ({ ...prev, attendance: updatedAttendance }));
  };

  if (!state.currentUser) {
    return <div className="flex items-center justify-center min-h-screen font-black text-slate-300">LOADING PORTAL...</div>;
  }

  return (
    <div className="flex h-screen bg-slate-50 overflow-hidden font-['Inter'] relative">
      {/* Mobile Sidebar Backdrop */}
      {isSidebarOpen && (
        <div 
            className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-40 md:hidden transition-opacity duration-300"
            onClick={() => setSidebarOpen(false)}
        />
      )}

      <Sidebar 
        user={state.currentUser} 
        activeTab={activeTab} 
        isOpen={isSidebarOpen}
        onTabChange={(tab) => {
            setActiveTab(tab);
            setSidebarOpen(false);
        }} 
        onLogout={() => {}} 
      />
      
      <main className="flex-1 overflow-y-auto w-full">
        <div className="p-4 md:p-12 max-w-7xl mx-auto">
            <header className="mb-8 md:mb-12 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div className="flex items-center justify-between w-full md:w-auto">
                    <div>
                        <h1 className="text-2xl md:text-3xl font-black text-slate-800 tracking-tight">Daily Portal</h1>
                        <p className="hidden md:block text-slate-400 font-bold uppercase text-[10px] tracking-widest mt-1">
                            Logged in as {state.currentUser.name} • {new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })}
                        </p>
                    </div>
                    {/* Mobile Menu Toggle */}
                    <button 
                        onClick={() => setSidebarOpen(true)}
                        className="md:hidden w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-600 shadow-sm"
                    >
                        <i className="fa-solid fa-bars-staggered"></i>
                    </button>
                </div>

                <div className="flex items-center gap-2 md:gap-4 overflow-x-auto pb-2 md:pb-0 scrollbar-hide">
                  <button 
                    onClick={toggleIp}
                    className="whitespace-nowrap text-[10px] bg-white border border-slate-200 hover:bg-slate-50 text-slate-500 px-3 md:px-4 py-2 rounded-full font-black uppercase tracking-tighter transition-all shadow-sm shrink-0"
                  >
                    Simulate Network
                  </button>
                  <div className="flex items-center gap-3 bg-white p-1.5 md:p-2 pr-4 md:pr-6 rounded-full shadow-sm border border-slate-200 shrink-0">
                      <div className={`w-8 h-8 md:w-10 md:h-10 rounded-full flex items-center justify-center ${state.currentIp === state.settings.officeIpAddress ? 'bg-emerald-500' : 'bg-rose-500'} text-white shadow-lg`}>
                        <i className={`fa-solid ${state.currentIp === state.settings.officeIpAddress ? 'fa-wifi' : 'fa-plane'} text-xs md:text-base`}></i>
                      </div>
                      <div className="flex flex-col">
                        <span className="text-[10px] uppercase font-black text-slate-400 leading-none mb-0.5">Link Status</span>
                        <span className={`text-[10px] md:text-xs font-bold ${state.currentIp === state.settings.officeIpAddress ? 'text-emerald-600' : 'text-rose-500'}`}>
                            {state.currentIp === state.settings.officeIpAddress ? 'ACTIVE' : 'LOCKED'}
                        </span>
                      </div>
                  </div>
                </div>
            </header>

            <div className="pb-20 md:pb-0">
                {activeTab === 'dashboard' && (
                <Dashboard 
                    user={state.currentUser} 
                    attendance={state.attendance} 
                    settings={state.settings}
                    onClockIn={signIn}
                    onClockOut={signOut}
                />
                )}

                {activeTab === 'board' && (
                <PublicBoard 
                    employees={state.employees} 
                    attendance={state.attendance} 
                />
                )}

                {activeTab === 'reports' && (
                <Reports 
                    state={state}
                />
                )}

                {activeTab === 'admin' && state.currentUser.role === UserRole.ADMIN && (
                <Admin 
                    state={state} 
                    setState={setState} 
                />
                )}

                {activeTab === 'sql' && (
                    <div className="bg-white p-6 md:p-8 rounded-[2rem] shadow-sm border border-slate-200">
                        <div className="flex flex-col md:flex-row md:justify-between md:items-center mb-8 gap-4">
                            <h2 className="text-xl md:text-2xl font-black text-slate-800">Developer Dashboard</h2>
                            <button 
                                onClick={toggleRole}
                                className="bg-slate-900 text-white px-6 py-3 rounded-2xl text-[10px] md:text-xs font-black uppercase tracking-widest hover:bg-slate-800 transition-all shadow-lg text-center"
                            >
                                Switch Role (Current: {state.currentUser.role})
                            </button>
                        </div>
                        <div className="grid grid-cols-1 gap-6 md:gap-8">
                            <div className="group">
                                <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 px-2">Production MySQL Schema</h3>
                                <pre className="bg-slate-900 text-green-400 p-6 md:p-8 rounded-[1.5rem] md:rounded-[2rem] overflow-x-auto text-[10px] md:text-xs font-mono leading-relaxed shadow-2xl">
                                    {SQL_SCHEMA}
                                </pre>
                            </div>
                            <div className="group">
                                <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 px-2">PHP Connectivity Module</h3>
                                <pre className="bg-slate-900 text-blue-300 p-6 md:p-8 rounded-[1.5rem] md:rounded-[2rem] overflow-x-auto text-[10px] md:text-xs font-mono leading-relaxed shadow-2xl">
                                    {PHP_DB_CONN}
                                </pre>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
      </main>
    </div>
  );
};

export default App;
