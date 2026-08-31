import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon
} from '@heroicons/react/24/outline';

const GlassmorphismFloating: React.FC = () => {
    const { isCollapsed, setIsCollapsed, theme, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: { width: '260px', x: 20 },
        collapsed: { width: '80px', x: 20 }
    };

    const itemVariants = {
        hover: {
            x: 10,
            backgroundColor: 'rgba(255, 255, 255, 0.2)',
            transition: { type: 'spring' as const, stiffness: 300 }
        }
    };

    return (
        <motion.div
            initial={{ x: -100, opacity: 0 }}
            animate={{ x: 20, opacity: 1 }}
            className="fixed left-0 top-6 bottom-6 z-50 pointer-events-none"
        >
            <motion.aside
                variants={containerVariants}
                animate={isCollapsed ? 'collapsed' : 'expanded'}
                transition={{ type: 'spring', damping: 20, stiffness: 100 }}
                className="h-full bg-white/20 backdrop-blur-xl border border-white/30 shadow-2xl rounded-[2.5rem] flex flex-col pointer-events-auto overflow-hidden text-white group"
                style={{
                    background: 'linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.05) 100%)',
                    boxShadow: '0 8px 32px 0 rgba(31, 38, 135, 0.15)'
                }}
            >
                {/* Header */}
                <div className="p-6 flex items-center justify-between">
                    {!isCollapsed && (
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            className="flex items-center gap-3"
                        >
                            <div className="w-10 h-10 bg-gradient-to-br from-blue-400 to-cyan-400 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-500/20">
                                <span className="font-black text-white">S</span>
                            </div>
                            <span className="font-black text-xl tracking-tight text-white drop-shadow-md">Sidebar</span>
                        </motion.div>
                    )}
                    {isCollapsed && (
                        <div className="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center mx-auto">
                            <span className="font-bold text-blue-200">S</span>
                        </div>
                    )}
                </div>

                {/* Search */}
                <div className="px-4 mb-4">
                    <div className="relative group/search">
                        <MagnifyingGlassIcon className={`w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 transition-colors ${isCollapsed ? 'text-white/40 group-hover/search:text-white' : 'text-white/60'}`} />
                        {!isCollapsed && (
                            <input
                                type="text"
                                placeholder="Search..."
                                className="w-full bg-white/10 border border-white/10 rounded-2xl py-2 pl-10 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-white/20 transition-all placeholder:text-white/40"
                            />
                        )}
                    </div>
                </div>

                {/* Menu */}
                <nav className="flex-1 px-4 space-y-2 overflow-y-auto no-scrollbar">
                    {navigationItems.map((item) => (
                        <motion.a
                            href={item.path}
                            key={item.id}
                            whileHover="hover"
                            variants={itemVariants}
                            className="flex items-center gap-4 p-3 rounded-2xl cursor-pointer transition-colors relative block"
                        >
                            <div className="text-blue-100 drop-shadow-sm">{item.icon}</div>
                            {!isCollapsed && (
                                <motion.span
                                    initial={{ opacity: 0, x: -10 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    className="font-bold text-sm tracking-wide text-white"
                                >
                                    {item.label}
                                </motion.span>
                            )}
                            {item.badge && !isCollapsed && (
                                <span className="ml-auto bg-white/20 px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-tighter shadow-inner">
                                    {item.badge}
                                </span>
                            )}
                        </motion.a>
                    ))}

                    <div className="h-px bg-white/10 my-4 mx-2" />

                    {secondaryItems.map((item) => (
                        <motion.a
                            href={item.path}
                            key={item.id}
                            whileHover="hover"
                            variants={itemVariants}
                            className="flex items-center gap-4 p-3 rounded-2xl cursor-pointer transition-colors group/item block"
                        >
                            <div className="text-white/60 group-hover/item:text-white transition-colors">
                                {item.icon}
                            </div>
                            {!isCollapsed && (
                                <span className="font-bold text-sm text-white/80 group-hover:text-white transition-colors">
                                    {item.label}
                                </span>
                            )}
                        </motion.a>
                    ))}
                </nav>

                {/* Profile */}
                <div className="p-4 mt-auto">
                    <div className="bg-white/10 rounded-[2rem] p-3 flex items-center gap-3 border border-white/10 shadow-inner group/profile transition-all hover:bg-white/20 cursor-pointer">
                        <img
                            src={currentUser.avatar}
                            alt="User"
                            className="w-10 h-10 rounded-full border-2 border-white/30 shadow-lg group-hover/profile:scale-110 transition-transform"
                        />
                        {!isCollapsed && (
                            <div className="flex-1 overflow-hidden">
                                <p className="text-sm font-black text-white truncate drop-shadow-sm">{currentUser.name}</p>
                                <p className="text-[10px] text-white/40 font-bold uppercase tracking-widest truncate">{currentUser.role}</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Toggle Button */}
                <button
                    onClick={() => setIsCollapsed(!isCollapsed)}
                    className="absolute -right-3 top-20 w-8 h-8 flex items-center justify-center bg-white/30 backdrop-blur-3xl border border-white/40 rounded-full shadow-2xl text-white hover:bg-white/50 transition-all hover:scale-110 hover:shadow-white/20 active:scale-95 z-[100]"
                >
                    {isCollapsed ? <ChevronRightIcon width={16} height={16} strokeWidth={3} /> : <ChevronLeftIcon width={16} height={16} strokeWidth={3} />}
                </button>
            </motion.aside>
        </motion.div>
    );
};

export default GlassmorphismFloating;
