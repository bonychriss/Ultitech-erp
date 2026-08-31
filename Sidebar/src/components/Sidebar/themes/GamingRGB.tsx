import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';

const GamingRGB: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: { width: '260px' },
        collapsed: { width: '80px' }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            className="fixed left-0 top-0 bottom-0 z-50 bg-black flex flex-col p-4 font-['Orbitron',_sans-serif] group"
            style={{
                boxShadow: '0 0 40px rgba(255, 0, 0, 0.2)'
            }}
        >
            {/* RGB Infinite Border Effect */}
            <div className="absolute inset-0 p-1 bg-gradient-to-br from-red-600 via-purple-600 to-blue-600 animate-rgb-cycle pointer-events-none opacity-40 rounded-r-3xl" />
            <div className="absolute inset-[2px] bg-black rounded-r-[1.3rem] pointer-events-none" />

            <div className="relative z-10 space-y-12">
                <header className="p-4 flex items-center gap-4">
                    <div className="w-12 h-12 bg-gray-900 border-2 border-red-500 shadow-[0_0_15px_rgba(239,68,68,0.5)] rounded-2xl flex items-center justify-center group-hover:animate-pulse">
                        <span className="text-2xl font-black text-red-500">GAM</span>
                    </div>
                    {!isCollapsed && (
                        <span className="text-xl font-black text-white italic tracking-tighter">PHOENIX <span className="text-red-500">PRO</span></span>
                    )}
                </header>

                <nav className="space-y-4 px-2">
                    {navigationItems.map((item) => (
                        <motion.a
                            href={item.path}
                            key={item.id}
                            whileHover={{
                                scale: 1.05,
                                x: 5,
                                backgroundColor: 'rgba(255, 255, 255, 0.05)',
                                borderColor: 'rgba(255, 0, 255, 0.4)'
                            }}
                            className="flex items-center gap-5 p-3.5 rounded-2xl cursor-pointer border-l-2 border-transparent transition-all group/item overflow-hidden block"
                        >
                            <div className="text-white drop-shadow-[0_0_8px_rgba(255,255,255,0.5)] group-hover/item:text-red-500 transition-colors">
                                {item.icon}
                            </div>
                            {!isCollapsed && (
                                <span className="text-xs font-black uppercase tracking-widest text-white/70 group-hover/item:text-white">
                                    {item.label}
                                </span>
                            )}
                            {/* RGB Hover Glow */}
                            <div className="absolute inset-0 bg-gradient-to-r from-cyan-500/20 to-purple-500/10 opacity-0 group-hover/item:opacity-100 transition-opacity" />
                        </motion.a>
                    ))}
                </nav>

                <div className="px-2 space-y-2 mt-auto">
                    {secondaryItems.map((item) => (
                        <motion.a
                            href={item.path}
                            key={item.id}
                            whileHover={{ x: 5, color: '#ff00ff' }}
                            className="flex items-center gap-5 p-3 rounded-xl cursor-pointer text-white/30 text-[11px] font-bold uppercase tracking-tight block"
                        >
                            {item.icon}
                            {!isCollapsed && <span>{item.label}</span>}
                        </motion.a>
                    ))}
                </div>

                <footer className="p-2 pt-10">
                    <div className={`flex items-center gap-4 p-3 bg-white/5 rounded-3xl border border-white/10 ${isCollapsed ? 'justify-center' : ''}`}>
                        <div className="relative">
                            <img src={currentUser.avatar} alt="Gamer" className="w-10 h-10 rounded-2xl border-2 border-red-500 shadow-[0_0_10px_rgba(239,68,68,0.3)]" />
                            <div className="absolute -top-1 -right-1 w-3 h-3 bg-red-600 rounded-full border-2 border-black animate-ping" />
                        </div>
                        {!isCollapsed && (
                            <div className="flex-1 overflow-hidden">
                                <p className="text-xs font-black text-white uppercase italic">{currentUser.name}</p>
                                <p className="text-[9px] text-white/30 font-bold uppercase tracking-tighter">ULTIMATE_RANK_88</p>
                            </div>
                        )}
                    </div>
                </footer>
            </div>

            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute top-24 -right-4 w-8 h-8 bg-black border-2 border-red-600 rounded-xl flex items-center justify-center text-red-600 hover:text-white hover:border-white shadow-[0_0_20px_rgba(239,68,68,0.5)] transition-all z-50 transform hover:scale-110 active:scale-95"
            >
                {isCollapsed ? '+' : '-'}
            </button>
        </motion.aside>
    );
};

export default GamingRGB;
