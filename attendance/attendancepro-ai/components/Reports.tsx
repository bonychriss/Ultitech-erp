
import React from 'react';
import { AppState, UserRole, AttendanceStatus } from '../types.ts';

interface ReportsProps {
  state: AppState;
}

const Reports: React.FC<ReportsProps> = ({ state }) => {
  const { attendance, employees, currentUser } = state;
  const isAdmin = currentUser?.role === UserRole.ADMIN;

  // Data preparation for weekly chart (Last 7 days)
  const last7Days = Array.from({ length: 7 }, (_, i) => {
    const d = new Date();
    d.setDate(d.getDate() - i);
    return d.toISOString().split('T')[0];
  }).reverse();

  const weeklyStats = last7Days.map(date => {
    const dailyRecords = attendance.filter(r => 
        r.date === date && 
        (isAdmin ? true : r.employeeId === currentUser?.id)
    );
    const totalHours = dailyRecords.reduce((sum, r) => sum + r.totalHours, 0);
    const otHours = dailyRecords.reduce((sum, r) => sum + r.overtimeHours, 0);
    return { date, totalHours, otHours, label: new Date(date).toLocaleDateString('en-US', { weekday: 'short' }) };
  });

  const maxHours = Math.max(...weeklyStats.map(s => s.totalHours + s.otHours), 10);

  const overtimeByEmployee = employees.map(emp => {
    const totalOT = attendance
        .filter(r => r.employeeId === emp.id)
        .reduce((sum, r) => sum + r.overtimeHours, 0);
    return { name: emp.name, totalOT, avatar: emp.avatar };
  }).sort((a, b) => b.totalOT - a.totalOT);

  return (
    <div className="space-y-6 md:space-y-8 animate-in fade-in duration-500">
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-8">
        
        {/* BAR GRAPH: Weekly Workload */}
        <div className="bg-white p-6 md:p-8 rounded-[1.5rem] md:rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
          <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 md:mb-8 gap-4">
            <div>
              <h3 className="text-lg md:text-xl font-black text-slate-800">Weekly Performance</h3>
              <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Hours Tracked</p>
            </div>
            <div className="flex gap-4">
                <div className="flex items-center gap-2">
                    <div className="w-2 h-2 md:w-3 md:h-3 rounded bg-indigo-500"></div>
                    <span className="text-[9px] md:text-[10px] font-black uppercase text-slate-400">Regular</span>
                </div>
                <div className="flex items-center gap-2">
                    <div className="w-2 h-2 md:w-3 md:h-3 rounded bg-amber-400"></div>
                    <span className="text-[9px] md:text-[10px] font-black uppercase text-slate-400">Overtime</span>
                </div>
            </div>
          </div>

          <div className="flex items-end justify-between h-48 md:h-64 gap-1 md:gap-2 px-1 border-b border-slate-100">
            {weeklyStats.map((stat, idx) => {
                const regularHeight = (stat.totalHours / maxHours) * 100;
                const otHeight = (stat.otHours / maxHours) * 100;
                
                return (
                    <div key={idx} className="flex-1 flex flex-col items-center group relative h-full justify-end">
                        <div className="absolute -top-10 opacity-0 group-hover:opacity-100 transition-opacity bg-slate-900 text-white text-[9px] md:text-[10px] px-2 py-1 rounded font-bold pointer-events-none z-20 whitespace-nowrap">
                            {stat.totalHours}h / {stat.otHours}h
                        </div>
                        <div 
                            style={{ height: `${otHeight}%` }} 
                            className="w-full max-w-[32px] md:max-w-[40px] bg-amber-400 rounded-t-sm md:rounded-t-lg transition-all duration-500"
                        ></div>
                        <div 
                            style={{ height: `${regularHeight}%` }} 
                            className="w-full max-w-[32px] md:max-w-[40px] bg-indigo-500 transition-all duration-500 relative z-10"
                        ></div>
                        <span className="mt-4 text-[8px] md:text-[10px] font-black text-slate-400 uppercase truncate w-full text-center">{stat.label}</span>
                    </div>
                );
            })}
          </div>
        </div>

        {/* STAT CARDS: Insights */}
        <div className="space-y-6">
            <div className="bg-indigo-600 p-6 md:p-8 rounded-[1.5rem] md:rounded-[2.5rem] text-white shadow-xl relative overflow-hidden">
                <div className="relative z-10">
                    <p className="text-[10px] font-black uppercase tracking-widest opacity-60 mb-1">Attendance Health</p>
                    <div className="flex items-end gap-3 mb-6">
                        <h4 className="text-4xl md:text-5xl font-black">94%</h4>
                        <span className="text-indigo-200 text-xs md:text-sm font-bold mb-1">On-Time Rate</span>
                    </div>
                    <div className="w-full bg-indigo-500 rounded-full h-3 mb-4">
                        <div className="bg-white h-full rounded-full w-[94%]"></div>
                    </div>
                    <p className="text-[10px] text-indigo-100 font-bold leading-relaxed max-w-sm">
                        Exceptional month. Maintaining your consistent arrival pattern keeps productivity at peak levels.
                    </p>
                </div>
                <i className="fa-solid fa-heart-pulse absolute -right-4 -bottom-4 text-8xl md:text-9xl text-white/5 rotate-12"></i>
            </div>

            <div className="bg-white p-6 md:p-8 rounded-[1.5rem] md:rounded-[2.5rem] shadow-sm border border-slate-200">
                <h3 className="text-[10px] font-black text-slate-800 uppercase tracking-widest mb-6">Top OT Contributors</h3>
                <div className="space-y-5">
                    {overtimeByEmployee.slice(0, 3).map((emp, i) => (
                        <div key={i} className="flex items-center gap-3 md:gap-4 group">
                            <img src={emp.avatar} className="w-8 h-8 md:w-10 md:h-10 rounded-full" alt="avatar" />
                            <div className="flex-1">
                                <div className="flex justify-between items-center mb-1">
                                    <p className="text-[10px] md:text-xs font-black text-slate-700">{emp.name}</p>
                                    <p className="text-[10px] md:text-xs font-black text-indigo-600">{emp.totalOT}h</p>
                                </div>
                                <div className="w-full bg-slate-100 rounded-full h-1.5 md:h-2 overflow-hidden">
                                    <div 
                                        className="bg-indigo-500 h-full rounded-full transition-all duration-1000" 
                                        style={{ width: `${(emp.totalOT / (overtimeByEmployee[0].totalOT || 1)) * 100}%` }}
                                    ></div>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
      </div>

      {/* LOWER SECTION: Monthly Summary */}
      <div className="bg-slate-900 p-8 md:p-10 rounded-[1.5rem] md:rounded-[3rem] text-white">
        <div className="flex flex-col lg:flex-row justify-between items-center gap-8">
            <div className="grid grid-cols-2 md:grid-cols-3 gap-8 md:gap-12 w-full lg:w-auto text-center md:text-left">
                <div>
                    <p className="text-slate-500 text-[9px] md:text-[10px] font-black uppercase tracking-widest mb-1">Monthly Total</p>
                    <p className="text-xl md:text-3xl font-black">168.5h</p>
                </div>
                <div>
                    <p className="text-slate-500 text-[9px] md:text-[10px] font-black uppercase tracking-widest mb-1">Late Instances</p>
                    <p className="text-xl md:text-3xl font-black text-rose-500">2</p>
                </div>
                <div className="col-span-2 md:col-span-1">
                    <p className="text-slate-500 text-[9px] md:text-[10px] font-black uppercase tracking-widest mb-1">Average Shift</p>
                    <p className="text-xl md:text-3xl font-black">8.2h</p>
                </div>
            </div>
            <button className="w-full lg:w-auto bg-white text-slate-900 px-8 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-slate-100 transition-all shadow-xl">
                Download Detailed Report
            </button>
        </div>
      </div>
    </div>
  );
};

export default Reports;
