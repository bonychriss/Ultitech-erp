
import React from 'react';
import { UserRole, Employee } from '../types.ts';

interface SidebarProps {
  user: Employee;
  activeTab: 'dashboard' | 'board' | 'admin' | 'sql' | 'reports';
  isOpen: boolean;
  onTabChange: (tab: 'dashboard' | 'board' | 'admin' | 'sql' | 'reports') => void;
  onLogout: () => void;
}

const Sidebar: React.FC<SidebarProps> = ({ user, activeTab, onTabChange, isOpen }) => {
  return (
    <aside className={`
        fixed inset-y-0 left-0 w-64 bg-slate-900 text-slate-300 flex flex-col z-50 transition-transform duration-300 ease-in-out md:static md:translate-x-0
        ${isOpen ? 'translate-x-0' : '-translate-x-full'}
    `}>
      <div className="p-8 flex items-center justify-between">
        <div className="flex items-center gap-3">
            <div className="bg-indigo-600 p-2 rounded-xl text-white shadow-lg shadow-indigo-900/50">
                <i className="fa-solid fa-clock-rotate-left"></i>
            </div>
            <span className="font-black text-xl text-white tracking-tighter">Attendo</span>
        </div>
      </div>

      <nav className="flex-1 px-4 space-y-2 mt-4">
        <button 
          onClick={() => onTabChange('dashboard')}
          className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 ${activeTab === 'dashboard' ? 'bg-white text-slate-900 shadow-lg' : 'hover:bg-slate-800 font-medium'}`}
        >
          <i className="fa-solid fa-house-user"></i> Dashboard
        </button>
        <button 
          onClick={() => onTabChange('reports')}
          className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 ${activeTab === 'reports' ? 'bg-white text-slate-900 shadow-lg' : 'hover:bg-slate-800 font-medium'}`}
        >
          <i className="fa-solid fa-chart-pie"></i> Reports
        </button>
        <button 
          onClick={() => onTabChange('board')}
          className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 ${activeTab === 'board' ? 'bg-white text-slate-900 shadow-lg' : 'hover:bg-slate-800 font-medium'}`}
        >
          <i className="fa-solid fa-globe"></i> Team Board
        </button>
        {user.role === UserRole.ADMIN && (
          <button 
            onClick={() => onTabChange('admin')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 ${activeTab === 'admin' ? 'bg-white text-slate-900 shadow-lg' : 'hover:bg-slate-800 font-medium'}`}
          >
            <i className="fa-solid fa-shield-halved"></i> Settings
          </button>
        )}
        <div className="pt-4 mt-4 border-t border-slate-800">
            <button 
            onClick={() => onTabChange('sql')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-300 ${activeTab === 'sql' ? 'bg-white text-slate-900 shadow-lg' : 'hover:bg-slate-800 font-medium'}`}
            >
            <i className="fa-solid fa-database text-xs opacity-50"></i> Developer
            </button>
        </div>
      </nav>

      <div className="p-6">
        <div className="bg-slate-800/50 rounded-2xl p-4 flex items-center gap-3 border border-slate-700/50">
            <img src={user.avatar} className="w-10 h-10 rounded-full border-2 border-slate-600 shrink-0" alt="avatar" />
            <div className="overflow-hidden">
                <p className="text-xs font-black text-white truncate">{user.name}</p>
                <p className="text-[10px] text-slate-500 font-bold uppercase tracking-widest truncate">{user.role}</p>
            </div>
        </div>
      </div>
    </aside>
  );
};

export default Sidebar;
