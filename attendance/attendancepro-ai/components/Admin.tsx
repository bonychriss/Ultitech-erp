
import React, { useState } from 'react';
import { AppState, UserRole, Employee, AttendanceStatus, AttendanceRecord } from '../types.ts';
import { getAttendanceInsights } from '../geminiService.ts';

interface AdminProps {
  state: AppState;
  setState: React.Dispatch<React.SetStateAction<AppState>>;
}

const Admin: React.FC<AdminProps> = ({ state, setState }) => {
  const [aiInsight, setAiInsight] = useState<string | null>(null);
  const [loadingAi, setLoadingAi] = useState(false);
  const [editingRecord, setEditingRecord] = useState<AttendanceRecord | null>(null);

  const generateInsights = async () => {
    setLoadingAi(true);
    const result = await getAttendanceInsights(state.attendance, state.employees);
    setAiInsight(result);
    setLoadingAi(false);
  };

  const updateSettings = (field: string, value: string | number) => {
    setState(prev => ({
      ...prev,
      settings: { ...prev.settings, [field]: value }
    }));
  };

  const deleteEmployee = (id: string) => {
    if (confirm('Are you sure you want to remove this employee?')) {
        setState(prev => ({
            ...prev,
            employees: prev.employees.filter(e => e.id !== id)
        }));
    }
  };

  const saveEdit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingRecord) return;
    
    setState(prev => ({
        ...prev,
        attendance: prev.attendance.map(r => r.id === editingRecord.id ? editingRecord : r)
    }));
    setEditingRecord(null);
  };

  const totalOtPay = state.attendance.reduce((acc, rec) => {
    const emp = state.employees.find(e => e.id === rec.employeeId);
    if (!emp) return acc;
    return acc + (rec.overtimeHours * emp.hourlyRate);
  }, 0);

  const exportReport = () => {
    const headers = ['Employee', 'Date', 'Time In', 'Time Out', 'Status', 'Overtime', 'Total Hours'];
    const rows = state.attendance.map(r => [
        state.employees.find(e => e.id === r.employeeId)?.name || 'Unknown',
        r.date, r.timeIn, r.timeOut || '-', r.status, r.overtimeHours, r.totalHours
    ]);
    const csvContent = "data:text/csv;charset=utf-8," + [headers, ...rows].map(e => e.join(",")).join("\n");
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `attendance_report_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (
    <div className="space-y-8 pb-12">
      {/* Settings Grid */}
      <section>
        <h2 className="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4 flex items-center gap-2">
            <i className="fa-solid fa-sliders"></i> System Settings
        </h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-4">
            <label className="block">
              <span className="text-slate-600 text-sm font-medium">Office Start Time</span>
              <input 
                type="time" 
                value={state.settings.startTime}
                onChange={(e) => updateSettings('startTime', e.target.value)}
                className="mt-1 block w-full rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-sm" 
              />
            </label>
            <label className="block">
              <span className="text-slate-600 text-sm font-medium">Office End Time</span>
              <input 
                type="time" 
                value={state.settings.endTime}
                onChange={(e) => updateSettings('endTime', e.target.value)}
                className="mt-1 block w-full rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-sm" 
              />
            </label>
          </div>

          <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
            <label className="block mb-4">
              <span className="text-slate-600 text-sm font-medium">Authorized Office IP</span>
              <input 
                type="text" 
                value={state.settings.officeIpAddress}
                onChange={(e) => updateSettings('officeIpAddress', e.target.value)}
                className="mt-1 block w-full rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-sm font-mono" 
                placeholder="e.g. 192.168.1.100"
              />
            </label>
            <p className="text-xs text-slate-400 bg-indigo-50 p-2 rounded-md border border-indigo-100 leading-relaxed">
                Clock-in actions are only allowed when the visitor IP matches this address.
            </p>
          </div>

          <div className="bg-indigo-600 p-6 rounded-2xl shadow-md text-white flex flex-col justify-center">
            <p className="text-indigo-100 text-sm mb-1">Total Overtime Payouts</p>
            <p className="text-3xl font-bold">${totalOtPay.toFixed(2)}</p>
            <div className="mt-4 flex gap-2">
                <button 
                  onClick={exportReport}
                  className="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors flex items-center gap-2"
                >
                    <i className="fa-solid fa-file-export"></i> Export CSV Report
                </button>
            </div>
          </div>
        </div>
      </section>

      {/* Gemini AI Insights */}
      <section>
        <div className="bg-gradient-to-r from-indigo-600 to-violet-600 p-6 rounded-3xl shadow-lg relative overflow-hidden">
            <div className="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div className="flex-1">
                    <h2 className="text-xl font-bold text-white mb-2 flex items-center gap-2">
                        <i className="fa-solid fa-wand-magic-sparkles"></i> AI Workforce Insights
                    </h2>
                    <p className="text-indigo-100 text-sm max-w-xl">
                        Powered by Gemini 3, we analyze your team's patterns to suggest improvements, identify burnout, and reward consistency.
                    </p>
                </div>
                <button 
                    onClick={generateInsights}
                    disabled={loadingAi}
                    className="bg-white text-indigo-600 px-6 py-3 rounded-xl font-bold hover:bg-indigo-50 transition-colors shadow-lg disabled:opacity-50 flex items-center gap-2 shrink-0"
                >
                    {loadingAi ? <i className="fa-solid fa-spinner animate-spin"></i> : <i className="fa-solid fa-brain"></i>}
                    {aiInsight ? 'Refresh Analysis' : 'Run AI Analysis'}
                </button>
            </div>
            
            {aiInsight && (
                <div className="mt-6 bg-white/10 backdrop-blur-md rounded-2xl p-6 text-white border border-white/20 animate-in fade-in slide-in-from-bottom-2">
                    <h3 className="text-xs font-bold uppercase tracking-widest text-indigo-200 mb-3">Analysis Result</h3>
                    <div className="prose prose-sm prose-invert max-w-none whitespace-pre-line text-sm leading-relaxed">
                        {aiInsight}
                    </div>
                </div>
            )}
        </div>
      </section>

      {/* Employee List */}
      <section>
        <h2 className="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4 flex items-center gap-2">
            <i className="fa-solid fa-users-gear"></i> Manage Employees
        </h2>
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
          <table className="w-full text-left">
            <thead>
              <tr className="bg-slate-50/50 text-slate-400 text-xs font-semibold uppercase">
                <th className="px-6 py-4">Employee</th>
                <th className="px-6 py-4">Email</th>
                <th className="px-6 py-4">Role</th>
                <th className="px-6 py-4">Rate</th>
                <th className="px-6 py-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {state.employees.map(emp => (
                <tr key={emp.id} className="hover:bg-slate-50/50 transition-colors">
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-3">
                      <img src={emp.avatar} className="w-8 h-8 rounded-full" alt="avatar" />
                      <span className="font-medium text-slate-700">{emp.name}</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-slate-600 text-sm">{emp.email}</td>
                  <td className="px-6 py-4">
                    <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${emp.role === UserRole.ADMIN ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600'}`}>
                      {emp.role}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-sm font-semibold text-slate-700">${emp.hourlyRate}/hr</td>
                  <td className="px-6 py-4 text-right">
                    <button className="text-slate-400 hover:text-indigo-600 p-2 transition-colors">
                      <i className="fa-solid fa-pen-to-square"></i>
                    </button>
                    <button 
                        onClick={() => deleteEmployee(emp.id)}
                        className="text-slate-400 hover:text-rose-600 p-2 transition-colors"
                    >
                      <i className="fa-solid fa-trash-can"></i>
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {/* Attendance Log (Admin Editor) */}
      <section>
        <h2 className="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4 flex items-center gap-2">
            <i className="fa-solid fa-clipboard-list"></i> Detailed Attendance Records
        </h2>
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
          <table className="w-full text-left">
            <thead>
              <tr className="bg-slate-50/50 text-slate-400 text-xs font-semibold uppercase">
                <th className="px-6 py-4">Date</th>
                <th className="px-6 py-4">Employee</th>
                <th className="px-6 py-4">In / Out</th>
                <th className="px-6 py-4">Status</th>
                <th className="px-6 py-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {state.attendance.slice().reverse().map(rec => (
                <tr key={rec.id} className="hover:bg-slate-50/50 transition-colors text-sm">
                  <td className="px-6 py-4">{rec.date}</td>
                  <td className="px-6 py-4 font-medium text-slate-700">
                    {state.employees.find(e => e.id === rec.employeeId)?.name || 'Unknown'}
                  </td>
                  <td className="px-6 py-4 text-slate-500">
                    {rec.timeIn} - {rec.timeOut || '??:??'}
                  </td>
                  <td className="px-6 py-4">
                    <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${rec.status === AttendanceStatus.LATE ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>
                      {rec.status}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-right">
                    <button 
                      onClick={() => setEditingRecord(rec)}
                      className="text-indigo-600 hover:underline text-xs font-bold"
                    >
                      Edit
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {/* Edit Modal */}
      {editingRecord && (
        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl">
                <h3 className="text-xl font-bold mb-4">Edit Record</h3>
                <form onSubmit={saveEdit} className="space-y-4">
                    <label className="block">
                        <span className="text-xs font-bold text-slate-400 uppercase">Clock In</span>
                        <input 
                            type="time" 
                            className="w-full p-3 rounded-xl border border-slate-200 mt-1"
                            value={editingRecord.timeIn}
                            onChange={e => setEditingRecord({...editingRecord, timeIn: e.target.value})}
                        />
                    </label>
                    <label className="block">
                        <span className="text-xs font-bold text-slate-400 uppercase">Clock Out</span>
                        <input 
                            type="time" 
                            className="w-full p-3 rounded-xl border border-slate-200 mt-1"
                            value={editingRecord.timeOut || ''}
                            onChange={e => setEditingRecord({...editingRecord, timeOut: e.target.value})}
                        />
                    </label>
                    <div className="flex gap-2 pt-4">
                        <button type="submit" className="flex-1 bg-indigo-600 text-white py-3 rounded-xl font-bold">Save Changes</button>
                        <button type="button" onClick={() => setEditingRecord(null)} className="flex-1 bg-slate-100 text-slate-600 py-3 rounded-xl font-bold">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
      )}
    </div>
  );
};

export default Admin;
