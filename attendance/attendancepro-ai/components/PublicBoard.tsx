
import React from 'react';
import { Employee, AttendanceRecord, AttendanceStatus } from '../types';

interface PublicBoardProps {
  employees: Employee[];
  attendance: AttendanceRecord[];
}

const PublicBoard: React.FC<PublicBoardProps> = ({ employees, attendance }) => {
  const today = new Date().toISOString().split('T')[0];
  const todayAttendance = attendance.filter(r => r.date === today);
  
  const inOfficeCount = todayAttendance.filter(r => r.timeOut === null).length;
  const lateTodayCount = todayAttendance.filter(r => r.status === AttendanceStatus.LATE).length;
  
  const getEmployeeName = (id: string) => employees.find(e => e.id === id)?.name || 'Unknown';
  const getEmployeeAvatar = (id: string) => employees.find(e => e.id === id)?.avatar || '';

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4">
        <div className="bg-white p-4 md:p-5 rounded-2xl shadow-sm border border-slate-200">
            <div className="bg-indigo-50 w-8 h-8 md:w-10 md:h-10 rounded-lg md:rounded-xl flex items-center justify-center text-indigo-600 mb-2 md:mb-3">
                <i className="fa-solid fa-users text-sm md:text-base"></i>
            </div>
            <p className="text-slate-500 text-[9px] md:text-xs font-semibold uppercase">Signed In</p>
            <p className="text-lg md:text-2xl font-bold">{inOfficeCount} / {employees.length}</p>
        </div>
        <div className="bg-white p-4 md:p-5 rounded-2xl shadow-sm border border-slate-200">
            <div className="bg-rose-50 w-8 h-8 md:w-10 md:h-10 rounded-lg md:rounded-xl flex items-center justify-center text-rose-600 mb-2 md:mb-3">
                <i className="fa-solid fa-clock text-sm md:text-base"></i>
            </div>
            <p className="text-slate-500 text-[9px] md:text-xs font-semibold uppercase">Late Today</p>
            <p className="text-lg md:text-2xl font-bold">{lateTodayCount}</p>
        </div>
        <div className="bg-white p-4 md:p-5 rounded-2xl shadow-sm border border-slate-200">
            <div className="bg-emerald-50 w-8 h-8 md:w-10 md:h-10 rounded-lg md:rounded-xl flex items-center justify-center text-emerald-600 mb-2 md:mb-3">
                <i className="fa-solid fa-bolt text-sm md:text-base"></i>
            </div>
            <p className="text-slate-500 text-[9px] md:text-xs font-semibold uppercase">Total OT</p>
            <p className="text-lg md:text-2xl font-bold">
                {todayAttendance.reduce((acc, r) => acc + r.overtimeHours, 0).toFixed(1)}h
            </p>
        </div>
        <div className="bg-indigo-600 p-4 md:p-5 rounded-2xl shadow-md text-white">
            <div className="bg-white/20 w-8 h-8 md:w-10 md:h-10 rounded-lg md:rounded-xl flex items-center justify-center text-white mb-2 md:mb-3">
                <i className="fa-solid fa-award text-sm md:text-base"></i>
            </div>
            <p className="text-indigo-100 text-[9px] md:text-xs font-semibold uppercase">Top Earner</p>
            <p className="text-lg md:text-2xl font-bold truncate">John Doe</p>
        </div>
      </div>

      <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div className="px-5 py-4 border-b border-slate-100">
          <h3 className="font-bold text-slate-800 text-sm md:text-base">Live Status Board</h3>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left min-w-[450px]">
            <thead>
              <tr className="bg-slate-50/50 text-slate-400 text-[10px] font-semibold uppercase">
                <th className="px-5 py-4">Employee</th>
                <th className="px-5 py-4">In Time</th>
                <th className="px-5 py-4">Work Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {todayAttendance.length > 0 ? todayAttendance.map(record => (
                <tr key={record.id} className="hover:bg-slate-50/50 transition-colors text-xs md:text-sm">
                  <td className="px-5 py-4">
                    <div className="flex items-center gap-3">
                      <img src={getEmployeeAvatar(record.employeeId)} className="w-7 h-7 md:w-8 md:h-8 rounded-full" alt="avatar" />
                      <span className="font-medium text-slate-700">{getEmployeeName(record.employeeId)}</span>
                    </div>
                  </td>
                  <td className="px-5 py-4 text-slate-600 font-mono">{record.timeIn}</td>
                  <td className="px-5 py-4">
                    <span className={`flex items-center gap-1.5 font-medium ${record.timeOut ? 'text-slate-400' : 'text-emerald-500'}`}>
                      <span className={`w-2 h-2 rounded-full ${record.timeOut ? 'bg-slate-300' : 'bg-emerald-500 animate-pulse'}`}></span>
                      {record.timeOut ? `Out at ${record.timeOut}` : 'Active'}
                    </span>
                  </td>
                </tr>
              )) : (
                <tr>
                  <td colSpan={3} className="px-6 py-12 text-center text-slate-400 text-xs md:text-sm">
                    No active sessions found for today.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

export default PublicBoard;
