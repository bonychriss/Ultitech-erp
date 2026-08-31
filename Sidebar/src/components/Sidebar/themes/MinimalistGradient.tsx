import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon
} from '@heroicons/react/24/outline';

const MinimalistGradient: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: { width: '260px' },
        collapsed: { width: '80px' }
    };

    const itemVariants = {
        hover: {
            backgroundColor: 'rgba(255, 255, 255, 0.05)',
            x: 5,
            transition: { duration: 0.2 }
        }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            className="fixed left-0 top-0 bottom-0 z-50 bg-gradient-to-b from-slate-900 via-slate-900 to-gray-900 flex flex-col border-r border-white/5"
        >
            <div className="p-6 h-20 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-lg bg-gradient-to-tr from-indigo-500 to-purple-500 flex items-center justify-center">
                        <div className="w-3 h-3 bg-white rounded-full animate-pulse" />
                    </div>
                    {!isCollapsed && (
                        <motion.span
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            className="text-lg font-bold text-white tracking-tight"
                        >
                            Minimal
                        </motion.span>
                    )}
                </div>
            </div>

            <div className="px-4 py-4">
                <div className="relative group">
                    <MagnifyingGlassIcon className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
                    {!isCollapsed && (
                        <input
                            type="text"
                            placeholder="Search"
                            className="w-full bg-slate-800/50 border border-slate-700/50 rounded-xl py-2 pl-10 pr-4 text-xs text-slate-300 focus:outline-none focus:border-indigo-500/50 transition-colors placeholder:text-slate-600"
                        />
                    )}
                </div>
            </div>

            <nav className="flex-1 px-3 space-y-1 overflow-x-hidden pt-4">
                {navigationItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover="hover"
                        variants={itemVariants}
                        className="flex items-center gap-4 px-3 py-2.5 rounded-xl cursor-pointer group hover:text-white text-slate-400 transition-colors block"
                    >
                        <div className="group-hover:text-indigo-400 transition-colors">{item.icon}</div>
                        {!isCollapsed && (
                            <span className="text-sm font-medium">{item.label}</span>
                        )}
                        {item.badge && !isCollapsed && (
                            <span className="ml-auto text-[10px] font-bold text-indigo-400 bg-indigo-500/10 px-1.5 py-0.5 rounded-md">
                                {item.badge}
                            </span>
                        )}
                    </motion.a>
                ))}

                <div className="py-6 px-4">
                    <div className="h-px bg-slate-800" />
                </div>

                {secondaryItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover="hover"
                        variants={itemVariants}
                        className="flex items-center gap-4 px-3 py-2.5 rounded-xl cursor-pointer hover:text-white text-slate-400 transition-all block"
                    >
                        <div className="group-hover:text-slate-200">{item.icon}</div>
                        {!isCollapsed && (
                            <span className="text-sm font-medium">{item.label}</span>
                        )}
                    </motion.a>
                ))}
            </nav>

            <div className="p-4 border-t border-slate-800/50">
                <div className={`flex items-center gap-3 p-2 rounded-xl bg-slate-800/20 ${isCollapsed ? 'justify-center' : ''}`}>
                    <img src={currentUser.avatar} alt="User" className="w-8 h-8 rounded-full ring-2 ring-slate-700" />
                    {!isCollapsed && (
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-bold text-white truncate">{currentUser.name}</p>
                            <p className="text-[10px] text-slate-500 font-medium truncate uppercase tracking-tighter">{currentUser.role}</p>
                        </div>
                    )}
                </div>
            </div>

            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute bottom-6 -right-3 w-6 h-6 bg-slate-800 border border-slate-700 rounded-lg flex items-center justify-center text-slate-400 hover:text-white transition-all shadow-xl"
            >
                {isCollapsed ? <ChevronRightIcon className="w-3 h-3" /> : <ChevronLeftIcon className="w-3 h-3" />}
            </button>
        </motion.aside>
    );
};

export default MinimalistGradient;
