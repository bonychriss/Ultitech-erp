
import React, { useState } from 'react';

interface LoginProps {
  onLogin: (email: string) => void;
}

const Login: React.FC<LoginProps> = ({ onLogin }) => {
  const [email, setEmail] = useState('');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onLogin(email);
  };

  return (
    <div className="min-h-screen bg-slate-900 flex items-center justify-center p-4">
      <div className="w-full max-w-md bg-white rounded-3xl shadow-2xl overflow-hidden">
        <div className="p-8 pb-0 flex flex-col items-center">
            <div className="bg-indigo-600 w-16 h-16 rounded-2xl flex items-center justify-center text-white text-3xl shadow-lg shadow-indigo-100 mb-6">
                <i className="fa-solid fa-clock-rotate-left"></i>
            </div>
            <h1 className="text-2xl font-bold text-slate-900 mb-2">Welcome Back</h1>
            <p className="text-slate-500 text-sm text-center">Enter your workspace credentials to access AttendancePro AI</p>
        </div>

        <form onSubmit={handleSubmit} className="p-8 space-y-6">
          <div className="space-y-1.5">
            <label className="text-xs font-bold text-slate-400 uppercase tracking-widest">Email Address</label>
            <div className="relative">
                <i className="fa-solid fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input 
                    type="email" 
                    required 
                    className="w-full pl-12 pr-4 py-4 rounded-xl border border-slate-200 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none text-slate-700"
                    placeholder="name@office.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                />
            </div>
          </div>
          <div className="space-y-1.5">
            <label className="text-xs font-bold text-slate-400 uppercase tracking-widest">Password</label>
            <div className="relative">
                <i className="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input 
                    type="password" 
                    required 
                    className="w-full pl-12 pr-4 py-4 rounded-xl border border-slate-200 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none text-slate-700"
                    placeholder="••••••••"
                />
            </div>
          </div>
          <button 
            type="submit"
            className="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xl shadow-indigo-100 transition-all"
          >
            Sign In to Dashboard
          </button>
        </form>

        <div className="bg-slate-50 p-6 text-center border-t border-slate-100">
            <p className="text-xs text-slate-500 font-medium mb-2">Demo Accounts:</p>
            <div className="flex flex-col gap-1">
                <code className="text-[10px] text-slate-400">Admin: admin@office.com</code>
                <code className="text-[10px] text-slate-400">User: john@office.com</code>
            </div>
        </div>
      </div>
    </div>
  );
};

export default Login;
