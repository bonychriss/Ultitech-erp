import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';

const MacOSBigSur: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: { width: '260px' },
        collapsed: { width: '80px' }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            className="fixed left-0 top-0 bottom-0 z-50 bg-[#f6f6f6]/80 backdrop-blur-3xl border-r border-[#d1d1d1]/50 flex flex-col shadow-sm font-[-apple-system,_BlinkMacSystemFont,_-apple-system,_Segoe_UI,_Roboto,_Helvetica,_Arial,_sans-serif]"
        >
            <div className="p-6 h-16 flex items-center gap-2">
                {/* macOS Windows Controls */}
                <div className="flex gap-1.5">
                    <div className="w-3 h-3 rounded-full bg-[#ff5f57]" />
                    <div className="w-3 h-3 rounded-full bg-[#ffbd2e]" />
                    <div className="w-3 h-3 rounded-full bg-[#28c840]" />
                </div>
                {!isCollapsed && (
                    <span className="ml-4 font-bold text-slate-800/80 text-[13px] tracking-tight">System Admin</span>
                )}
            </div>

            <nav className="flex-1 px-3.5 space-y-0.5 overflow-x-hidden pt-4">
                {navigationItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileTap={{ scale: 0.98 }}
                        className={`flex items-center gap-3.5 px-3 py-1.5 rounded-lg cursor-default group transition-all block ${item.id === 'dashboard' ? 'bg-blue-600/10 text-blue-600' : 'text-slate-700/80'
                            } hover:bg-slate-200/50`}
                    >
                        <div className={`p-1 rounded-md ${item.id === 'dashboard' ? 'bg-blue-600 text-white shadow-sm shadow-blue-400/30' : 'text-slate-500/80 group-hover:text-slate-800'}`}>
                            {item.icon}
                        </div>
                        {!isCollapsed && (
                            <span className="text-[13px] font-medium tracking-wide">{item.label}</span>
                        )}
                        {item.badge && !isCollapsed && (
                            <span className="ml-auto text-[10px] font-black text-slate-400 bg-slate-400/10 px-1.5 py-0.5 rounded-md">
                                {item.badge}
                            </span>
                        )}
                    </motion.a>
                ))}

                <div className="py-4 px-4">
                    <div className="h-px bg-slate-300/40" />
                </div>

                {secondaryItems.map((item) => (
                    <a
                        href={item.path}
                        key={item.id}
                        className="flex items-center gap-3.5 px-3 py-1.5 rounded-lg cursor-default group hover:bg-slate-200/50 text-slate-700/80 transition-all font-medium text-[13px] block"
                    >
                        <div className="p-1 rounded-md text-slate-500/80 group-hover:text-slate-800">
                            {item.icon}
                        </div>
                        {!isCollapsed && (
                            <span>{item.label}</span>
                        )}
                    </a>
                ))}
            </nav>

            <div className="p-4 border-t border-slate-300/30">
                <div className={`flex items-center gap-3 p-1.5 rounded-xl transition-all ${isCollapsed ? 'justify-center' : 'hover:bg-slate-200/40 cursor-default'}`}>
                    <img src={currentUser.avatar} alt="Apple" className="w-9 h-9 rounded-lg shadow-sm" />
                    {!isCollapsed && (
                        <div className="flex-1 overflow-hidden">
                            <p className="text-[13px] font-bold text-slate-900 truncate leading-tight">{currentUser.name}</p>
                            <p className="text-[11px] text-slate-500 font-medium truncate capitalize">{currentUser.role}</p>
                        </div>
                    )}
                </div>
            </div>

            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute bottom-20 -right-2.5 w-5 h-5 bg-[#f6f6f6] border border-[#d1d1d1] rounded-full flex items-center justify-center text-slate-800/80 hover:bg-white shadow-sm transition-all z-[100]"
            >
                <span className="text-[10px] transform rotate-90 font-black">{isCollapsed ? '<' : '>'}</span>
            </button>
        </motion.aside>
    );
};

export default MacOSBigSur;
