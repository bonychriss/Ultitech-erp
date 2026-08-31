
import React from 'react';
import { Employee, AttendanceRecord, Settings, AttendanceStatus } from '../types';

interface DashboardProps {
  user: Employee;
  attendance: AttendanceRecord[];
  settings: Settings;
  onClockIn: () => void;
  onClockOut: () => void;
}

const Dashboard: React.FC<DashboardProps> = ({ user, attendance, settings, onClockIn, onClockOut }) => {
  const userHistory = attendance.filter(r => r.employeeId === user.id).reverse();
  const todayRecord = attendance.find(r => r.employeeId === user.id && r.date === new Date().toISOString().split('T')[0]);

  const isSignedOut = !todayRecord?.timeIn;
  const isCurrentlyWorking = todayRecord?.timeIn && !todayRecord?.timeOut;
  const isFinished = todayRecord?.timeIn && todayRecord?.timeOut;

  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-1 space-y-6">
        <div className="bg-white p-6 md:p-8 rounded-[1.5rem] md:rounded-[2rem] shadow-sm border border-slate-200">
            <div className="flex flex-col items-center text-center">
                <div className="relative mb-4 md:mb-6">
                    <img src={user.avatar} className="w-16 h-16 md:w-20 md:h-20 rounded-full border-4 border-white shadow-lg" alt="profile" />
                    <div className={`absolute bottom-0 right-0 w-5 h-5 md:w-6 md:h-6 rounded-full border-4 border-white shadow-sm ${isCurrentlyWorking ? 'bg-emerald-500' : 'bg-slate-300'}`}></div>
                </div>
                
                <h2 className="text-lg md:text-xl font-bold text-slate-800">{user.name}</h2>
                <p className="text-slate-400 text-[10px] font-semibold uppercase tracking-widest mb-6 md:mb-8">Workspace Member</p>
                
                {/* CIRCULAR POWER BUTTON */}
                <div className="relative mb-6 md:mb-8 group">
                    {isCurrentlyWorking && (
                        <div className="absolute inset-0 bg-emerald-400 rounded-full animate-ping opacity-20"></div>
                    )}
                    
                    <button 
                        onClick={() => {
                            if (isSignedOut) onClockIn();
                            else if (isCurrentlyWorking) onClockOut();
                        }}
                        disabled={!!isFinished}
                        className={`
                            relative w-28 h-28 md:w-32 md:h-32 rounded-full border-8 flex flex-col items-center justify-center transition-all duration-500 shadow-2xl
                            ${isSignedOut ? 'bg-slate-100 border-slate-200 text-slate-400 hover:bg-indigo-50 hover:border-indigo-100 hover:text-indigo-500 scale-100 active:scale-95' : ''}
                            ${isCurrentlyWorking ? 'bg-emerald-500 border-emerald-100 text-white scale-105 md:scale-110 shadow-emerald-200 active:scale-95' : ''}
                            ${isFinished ? 'bg-amber-50 border-amber-100 text-amber-500 cursor-not-allowed opacity-80' : ''}
                        `}
                    >
                        <i className={`fa-solid fa-power-off text-3xl md:text-4xl mb-1 ${isCurrentlyWorking ? 'animate-pulse' : ''}`}></i>
                        <span className="text-[10px] font-black uppercase tracking-widest">
                            {isSignedOut ? 'Off' : isCurrentlyWorking ? 'On' : 'Done'}
                        </span>
                    </button>
                </div>

                <div className="text-xs md:text-sm font-bold text-slate-600 mb-6">
                    {isSignedOut && <span className="text-slate-400">Ready to start? Push to Sign In</span>}
                    {isCurrentlyWorking && <span className="text-emerald-600">Shift active since {todayRecord.timeIn}</span>}
                    {isFinished && <span className="text-amber-600">Daily session completed</span>}
                </div>

                <div className="grid grid-cols-2 gap-2 md:gap-3 w-full">
                    <div className="bg-slate-50 p-3 md:p-4 rounded-xl md:rounded-2xl border border-slate-100">
                        <p className="text-[9px] md:text-[10px] text-slate-400 uppercase font-black mb-1">Office Start</p>
                        <p className="text-xs md:text-base font-bold text-slate-700">{settings.startTime}</p>
                    </div>
                    <div className="bg-slate-50 p-3 md:p-4 rounded-xl md:rounded-2xl border border-slate-100">
                        <p className="text-[9px] md:text-[10px] text-slate-400 uppercase font-black mb-1">Office End</p>
                        <p className="text-xs md:text-base font-bold text-slate-700">{settings.endTime}</p>
                    </div>
                </div>
            </div>
        </div>

        <div className="bg-indigo-900 p-6 md:p-8 rounded-[1.5rem] md:rounded-[2rem] text-white shadow-xl relative overflow-hidden group">
            <div className="absolute -right-10 -bottom-10 w-32 h-32 md:w-40 md:h-40 bg-white/5 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
            <div className="relative z-10">
                <div className="flex justify-between items-start mb-4 md:mb-6">
                    <div className="bg-white/10 p-2 md:p-3 rounded-xl md:rounded-2xl">
                        <i className="fa-solid fa-receipt text-lg md:text-xl"></i>
                    </div>
                    <span className="text-[10px] md:text-xs font-bold text-indigo-300 bg-white/5 px-3 py-1 rounded-full border border-white/10">
                        ${user.hourlyRate}/hr
                    </span>
                </div>
                <p className="text-indigo-200 text-[10px] md:text-xs font-bold uppercase tracking-widest mb-1">Daily Earnings</p>
                <p className="text-2xl md:text-4xl font-bold">
                    ${todayRecord ? (todayRecord.totalHours * user.hourlyRate).toFixed(2) : '0.00'}
                </p>
            </div>
        </div>
      </div>

      <div className="lg:col-span-2">
        <div className="bg-white rounded-[1.5rem] md:rounded-[2rem] shadow-sm border border-slate-200 overflow-hidden">
          <div className="px-6 md:px-8 py-5 md:py-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/30">
            <h3 className="font-bold text-slate-800 text-sm md:text-base flex items-center gap-2">
                <i className="fa-solid fa-clock-rotate-left text-indigo-500"></i>
                Attendance Log
            </h3>
            <span className="text-[9px] md:text-[10px] bg-white border border-slate-200 text-slate-400 px-2 md:px-3 py-1 rounded-full font-black uppercase tracking-tighter shrink-0">Recent activity</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left min-w-[500px]">
              <thead>
                <tr className="text-slate-400 text-[9px] md:text-[10px] font-black uppercase tracking-widest">
                  <th className="px-6 md:px-8 py-4 md:py-5">Date</th>
                  <th className="px-6 md:px-8 py-4 md:py-5">In</th>
                  <th className="px-6 md:px-8 py-4 md:py-5">Out</th>
                  <th className="px-6 md:px-8 py-4 md:py-5">Status</th>
                  <th className="px-6 md:px-8 py-4 md:py-5 text-right">OT</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {userHistory.length > 0 ? userHistory.map(record => (
                  <tr key={record.id} className="text-xs md:text-sm hover:bg-slate-50/80 transition-all group">
                    <td className="px-6 md:px-8 py-4 md:py-5 font-bold text-slate-700">{record.date}</td>
                    <td className="px-6 md:px-8 py-4 md:py-5 text-slate-500 font-mono">{record.timeIn}</td>
                    <td className="px-6 md:px-8 py-4 md:py-5 text-slate-500 font-mono">{record.timeOut || '--:--'}</td>
                    <td className="px-6 md:px-8 py-4 md:py-5">
                        <span className={`px-2 py-0.5 rounded text-[9px] md:text-[10px] font-black uppercase ${
                            record.status === AttendanceStatus.LATE ? 'bg-rose-50 text-rose-600' : 
                            record.status === AttendanceStatus.ON_TIME ? 'bg-emerald-50 text-emerald-600' : 
                            'bg-blue-50 text-blue-600'
                        }`}>
                            {record.status}
                        </span>
                    </td>
                    <td className="px-6 md:px-8 py-4 md:py-5 text-right font-black text-indigo-600">
                        {record.overtimeHours > 0 ? `+${record.overtimeHours}h` : '-'}
                    </td>
                  </tr>
                )) : (
                  <tr>
                    <td colSpan={5} className="px-8 py-20 text-center text-slate-300">
                        <div className="flex flex-col items-center">
                            <i className="fa-solid fa-calendar-day text-4xl mb-4 opacity-10"></i>
                            <p className="font-bold text-xs md:text-sm">No records found for this period.</p>
                        </div>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Dashboard;
