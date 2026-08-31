import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';

const Holographic: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: { width: '260px' },
        collapsed: { width: '80px' }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            className="fixed left-0 top-0 bottom-0 z-50 flex flex-col p-6 overflow-hidden bg-transparent"
        >
            {/* Hologram Core Background */}
            <div className="absolute inset-0 bg-cyan-950/40 backdrop-blur-md rounded-r-[4rem] border-r-2 border-cyan-400/40 shadow-[0_0_50px_rgba(34,211,238,0.2)]" />

            {/* Scanline Sweep Animation */}
            <motion.div
                animate={{ y: ['0%', '100%'], opacity: [0, 0.5, 0] }}
                transition={{ repeat: Infinity, duration: 4, ease: 'linear' }}
                className="absolute inset-x-0 h-2 bg-gradient-to-b from-transparent via-cyan-400 to-transparent pointer-events-none z-10"
            />

            <div className="relative z-20 space-y-12">
                <div className="flex items-center gap-5 p-2">
                    <div className="w-12 h-12 border-2 border-cyan-400 rounded-full flex items-center justify-center animate-pulse shadow-[0_0_20px_#22d3ee]">
                        <span className="text-xl font-black text-cyan-400 tracking-tighter">HOL</span>
                    </div>
                    {!isCollapsed && (
                        <div className="flex flex-col">
                            <span className="text-xl font-black text-cyan-200 tracking-widest uppercase italic">Project:X</span>
                            <span className="text-[10px] font-bold text-cyan-400 tracking-tighter uppercase whitespace-nowrap">Status: Secure Layer_0</span>
                        </div>
                    )}
                </div>

                <nav className="space-y-6">
                    {navigationItems.map((item) => (
                        <motion.a
                            href={item.path}
                            key={item.id}
                            whileHover={{
                                x: 15,
                                backgroundColor: 'rgba(34, 211, 238, 0.1)',
                                color: '#fff',
                                textShadow: '0 0 10px #22d3ee'
                            }}
                            className="group flex items-center gap-6 p-3 rounded-2xl cursor-pointer text-cyan-400/60 transition-all border border-transparent hover:border-cyan-400/30 block"
                        >
                            <div className="transition-transform group-hover:scale-125 group-hover:-rotate-6">
                                {item.icon}
                            </div>
                            {!isCollapsed && (
                                <span className="text-xs font-black uppercase tracking-[0.3em] font-mono">
                                    {item.label}
                                </span>
                            )}
                            {item.badge && !isCollapsed && (
                                <div className="ml-auto w-2 h-2 bg-cyan-400 rounded-full animate-ping shadow-[0_0_8px_#22d3ee]" />
                            )}
                        </motion.a>
                    ))}
                </nav>

                <div className="py-4 relative">
                    <div className="h-px bg-cyan-400/20" />
                    <div className="absolute inset-0 bg-cyan-400/5 blur-xl group-hover:bg-cyan-400/20" />
                </div>

                {secondaryItems.map((item) => (
                    <a
                        href={item.path}
                        key={item.id}
                        className="flex items-center gap-6 p-2 cursor-pointer text-cyan-800 hover:text-cyan-400 transition-all font-black text-[10px] uppercase group block"
                    >
                        <div className="group-hover:animate-spin-slow">
                            {item.icon}
                        </div>
                        {!isCollapsed && <span>{item.label}</span>}
                    </a>
                ))}

                <footer className="mt-auto pt-20">
                    <div className={`p-4 bg-cyan-400/5 border border-cyan-400/10 rounded-3xl flex items-center gap-4 transition-all hover:bg-cyan-400/10 ${isCollapsed ? 'justify-center' : ''}`}>
                        <div className="relative">
                            <img src={currentUser.avatar} alt="Holo" className="w-10 h-10 rounded-full border border-cyan-400 p-1 sepia contrast-125 opacity-80" />
                            <div className="absolute inset-0 bg-cyan-400/20 mix-blend-overlay rounded-full" />
                        </div>
                        {!isCollapsed && (
                            <div className="flex-1 overflow-hidden leading-snug">
                                <p className="text-[11px] font-black text-white uppercase tracking-wider truncate">{currentUser.name}</p>
                                <p className="text-[9px] text-cyan-400/40 font-bold truncate uppercase">{currentUser.role} [AUTH_OK]</p>
                            </div>
                        )}
                    </div>
                </footer>
            </div>

            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute top-1/2 -right-4 w-10 h-10 bg-cyan-400/10 border border-cyan-400 font-black text-cyan-400 hover:text-white hover:bg-cyan-400 rounded-lg flex items-center justify-center -translate-y-1/2 shadow-[0_0_15px_rgba(34,211,238,0.5)] transition-all z-50 transform hover:scale-125 active:skew-x-12"
            >
                {isCollapsed ? ' > ' : ' < '}
            </button>
        </motion.aside>
    );
};

export default Holographic;
