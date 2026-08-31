import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon
} from '@heroicons/react/24/outline';

const NeonCyberpunk: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: { width: '260px' },
        collapsed: { width: '80px' }
    };

    const itemVariants = {
        hover: {
            backgroundColor: 'rgba(255, 0, 255, 0.1)',
            borderLeft: '4px solid #ff00ff',
            boxShadow: 'inset 5px 0 20px rgba(255, 0, 255, 0.2)',
            transition: { duration: 0.2 }
        }
    };

    const glowText = {
        initial: { textShadow: '0 0 0px #0ff' },
        hover: {
            textShadow: [
                '0 0 5px #0ff',
                '0 0 10px #0ff',
                '0 0 5px #0ff'
            ],
            transition: { repeat: Infinity, duration: 1.5 }
        }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            transition={{ type: 'spring', damping: 15, stiffness: 100 }}
            className="fixed left-0 top-0 bottom-0 z-50 bg-[#0a0a0f] border-r-2 border-[#00f2ff]/30 flex flex-col overflow-hidden relative"
            style={{
                boxShadow: '10px 0 30px rgba(0, 242, 255, 0.05)'
            }}
        >
            {/* Scanline Effect */}
            <div className="absolute inset-0 pointer-events-none opacity-[0.03] z-[1]"
                style={{ background: 'linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06))', backgroundSize: '100% 2px, 3px 100%' }} />

            {/* Flicker Grid */}
            <div className="absolute inset-0 z-0 opacity-10 pointer-events-none"
                style={{ backgroundImage: 'radial-gradient(#00f2ff 0.5px, transparent 0.5px)', backgroundSize: '24px 24px' }} />

            {/* Header */}
            <div className="p-6 h-20 flex items-center border-b border-[#ff00ff]/20 z-10">
                <div className="flex items-center gap-4">
                    <motion.div
                        animate={{
                            boxShadow: ['0 0 5px #ff00ff', '0 0 15px #ff00ff', '0 0 5px #ff00ff'],
                            borderColor: ['#ff00ff', '#00f2ff', '#ff00ff']
                        }}
                        transition={{ duration: 4, repeat: Infinity }}
                        className="w-10 h-10 border-2 rounded-lg flex items-center justify-center font-black text-[#ff00ff] italic"
                    >
                        XP
                    </motion.div>
                    {!isCollapsed && (
                        <motion.h2
                            initial={{ opacity: 0, x: -20 }}
                            animate={{ opacity: 1, x: 0 }}
                            className="text-xl font-black italic tracking-tighter text-[#00f2ff] uppercase"
                            style={{ textShadow: '0 0 10px rgba(0, 242, 255, 0.5)' }}
                        >
                            Cyber<span className="text-[#ff00ff]">Core</span>
                        </motion.h2>
                    )}
                </div>
            </div>

            {/* Menu */}
            <nav className="flex-1 py-8 z-10 overflow-x-hidden no-scrollbar">
                {navigationItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover="hover"
                        variants={itemVariants}
                        className="flex items-center gap-4 px-6 py-4 cursor-pointer relative group border-l-4 border-transparent transition-all block"
                    >
                        <div className="text-[#00f2ff] drop-shadow-[0_0_5px_rgba(0,242,255,0.8)] group-hover:scale-110 transition-transform">
                            {item.icon}
                        </div>
                        {!isCollapsed && (
                            <motion.span
                                variants={glowText}
                                className="font-black text-sm uppercase tracking-widest text-[#00f2ff]/80"
                            >
                                {item.label}
                            </motion.span>
                        )}

                        {/* Active Indicator Line */}
                        <div className="absolute left-0 top-0 bottom-0 w-1 bg-[#ff00ff] opacity-0 group-hover:opacity-100 blur-[2px] transition-opacity" />
                    </motion.a>
                ))}

                < div className="px-6 my-8" >
                    <div className="h-[2px] bg-gradient-to-r from-[#ff00ff]/50 to-transparent" />
                </div>

                {secondaryItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover="hover"
                        variants={itemVariants}
                        className="flex items-center gap-4 px-6 py-4 cursor-pointer group border-l-4 border-transparent transition-all block"
                    >
                        <div className="text-[#ff00ff]/60 group-hover:text-[#ff00ff] drop-shadow-[0_0_5px_rgba(255,0,255,0.4)]">
                            {item.icon}
                        </div>
                        {!isCollapsed && (
                            <span className="font-bold text-xs uppercase tracking-[0.2em] text-[#ff00ff]/50 group-hover:text-[#ff00ff]">
                                {item.label}
                            </span>
                        )}
                    </motion.a>
                ))}
            </nav>

            {/* User */}
            <div className="p-6 border-t border-[#00f2ff]/20 bg-[#0f0f1a] z-10">
                <div className={`flex items-center gap-4 p-2 rounded-xl border border-transparent transition-all ${isCollapsed ? 'justify-center' : 'hover:border-[#ff00ff]/30 hover:bg-[#ff00ff]/5'}`}>
                    <div className="relative">
                        <img src={currentUser.avatar} alt="User" className="w-10 h-10 rounded-full border border-[#00f2ff] p-0.5 shadow-[0_0_10px_rgba(0,242,255,0.3)]" />
                        <div className="absolute -bottom-1 -right-1 w-3 h-3 bg-[#39ff14] rounded-full border-2 border-[#0a0a0f] animate-pulse shadow-[0_0_5px_#39ff14]" />
                    </div>
                    {!isCollapsed && (
                        <div className="flex-1 overflow-hidden">
                            <p className="text-sm font-black text-[#00f2ff] uppercase truncate">{currentUser.name}</p>
                            <p className="text-[10px] text-[#ff00ff] font-bold tracking-tighter italic truncate">{currentUser.role}</p>
                        </div>
                    )}
                </div>
            </div>

            {/* Toggle */}
            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute bottom-4 -right-3 w-8 h-8 rounded-full bg-[#0a0a0f] border-2 border-[#00f2ff] flex items-center justify-center text-[#ff00ff] hover:text-[#00f2ff] hover:scale-120 transition-all z-20 shadow-[0_0_10px_rgba(0,242,255,0.5)]"
            >
                {isCollapsed ? <ChevronRightIcon className="w-4 h-4" strokeWidth={3} /> : <ChevronLeftIcon className="w-4 h-4" strokeWidth={3} />}
            </button>
        </motion.aside >
    );
};

export default NeonCyberpunk;
