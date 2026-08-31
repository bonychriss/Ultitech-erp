import React from 'react'
import { SidebarProvider, useSidebar } from './context/SidebarContext'
import { SidebarManager } from './components/Sidebar/SidebarManager'
import { SidebarThemeId } from './types/sidebar'

const ThemeSwitcher: React.FC = () => {
  const { setTheme, theme } = useSidebar();

  const themes: SidebarThemeId[] = [
    'glassmorphism-floating',
    'neon-cyberpunk',
    'minimalist-gradient',
    '3d-depth',
    'retro-windows-98',
    'solarized-dark',
    'material-you',
    'macos-big-sur',
    'terminal-cli',
    'gaming-rgb',
    'paper-sketch',
    'holographic'
  ];

  return (
    <div className="fixed bottom-4 right-4 bg-white/80 backdrop-blur-md p-4 rounded-2xl shadow-2xl border border-gray-200 z-[100] max-w-xs transition-all hover:scale-105">
      <h4 className="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3">Switch Sidebar Style</h4>
      <div className="grid grid-cols-2 gap-2">
        {themes.map((t) => (
          <button
            key={t}
            onClick={() => setTheme(t)}
            className={`px-3 py-1.5 text-[10px] font-semibold rounded-lg transition-all capitalize ${theme === t
                ? 'bg-blue-600 text-white shadow-lg shadow-blue-200 scale-95'
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
              }`}
          >
            {t.split('-').join(' ')}
          </button>
        ))}
      </div>
    </div>
  );
};

const Layout: React.FC = () => {
  return (
    <div className="flex min-h-screen bg-gray-50 text-gray-900 font-sans selection:bg-blue-100 selection:text-blue-900">
      <SidebarManager />
      <main className="flex-1 p-8 lg:p-12">
        <div className="max-w-4xl mx-auto space-y-8">
          <header>
            <h1 className="text-4xl font-black text-gray-900 tracking-tight">System Dashboard</h1>
            <p className="text-gray-500 mt-2 text-lg">Welcome back, Alex. Here's what's happening today.</p>
          </header>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {[1, 2, 3].map((i) => (
              <div key={i} className="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-4">
                  <span className="text-xl font-bold">0{i}</span>
                </div>
                <h3 className="font-bold text-gray-900">Statistic Title</h3>
                <p className="text-gray-500 text-sm mt-1 font-medium">Monthly growth comparison</p>
                <div className="mt-4 text-2xl font-black text-gray-900">+12.5%</div>
              </div>
            ))}
          </div>

          <div className="bg-white p-8 rounded-[2.5rem] border border-gray-100 shadow-sm">
            <h2 className="text-xl font-bold text-gray-900 mb-6">Recent Activities</h2>
            <div className="space-y-4">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="flex items-center gap-4 py-3 border-b border-gray-50 last:border-0 hover:bg-gray-50 px-4 -mx-4 rounded-xl transition-colors">
                  <div className="w-10 h-10 rounded-full bg-gray-100" />
                  <div className="flex-1">
                    <div className="font-bold text-sm text-gray-900">Task completed by Team Alpha</div>
                    <div className="text-xs text-gray-400 mt-0.5">2 hours ago</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
        <ThemeSwitcher />
      </main>
    </div>
  );
};

function App() {
  return (
    <SidebarProvider>
      <Layout />
    </SidebarProvider>
  )
}

export default App
