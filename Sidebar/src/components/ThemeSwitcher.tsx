import React from 'react';
import { useSidebar } from '../context/SidebarContext';
import { SidebarThemeId } from '../types/sidebar';
import { Settings } from 'lucide-react';

export const ThemeSwitcher: React.FC = () => {
    const { setTheme, theme } = useSidebar();
    const [isOpen, setIsOpen] = React.useState(false);

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
        <div className="fixed bottom-4 left-4 z-[9999]">
            <button
                onClick={() => setIsOpen(!isOpen)}
                className="bg-white/80 hover:bg-white backdrop-blur-md p-2 rounded-full shadow-lg border border-gray-200 transition-all text-gray-600 hover:text-blue-600"
                title="Change Theme"
            >
                <Settings size={20} />
            </button>

            {isOpen && (
                <div className="absolute bottom-12 left-0 w-64 bg-white/90 backdrop-blur-xl p-4 rounded-2xl shadow-2xl border border-gray-200 animate-in fade-in slide-in-from-bottom-4">
                    <div className="flex justify-between items-center mb-3">
                        <h4 className="text-xs font-bold text-gray-500 uppercase tracking-widest">Theme</h4>
                        <button onClick={() => setIsOpen(false)} className="text-gray-400 hover:text-gray-600">×</button>
                    </div>
                    <div className="grid grid-cols-2 gap-2 max-h-[300px] overflow-y-auto custom-scrollbar">
                        {themes.map((t) => (
                            <button
                                key={t}
                                onClick={() => setTheme(t)}
                                className={`px-2 py-1.5 text-[10px] font-semibold rounded-lg transition-all capitalize text-left truncate ${theme === t
                                    ? 'bg-blue-600 text-white shadow-md'
                                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                    }`}
                            >
                                {t.split('-').join(' ')}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};
