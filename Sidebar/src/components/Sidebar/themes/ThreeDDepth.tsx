import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon
} from '@heroicons/react/24/outline';

const ThreeDDepth: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: {
            width: '260px',
            rotateY: 0,
            transition: { type: 'spring' as const, damping: 20 }
        },
        collapsed: {
            width: '80px',
            rotateY: -10,
            transition: { type: 'spring' as const, damping: 20 }
        }
    };

    const itemVariants = {
        hover: {
            scale: 1.05,
            z: 50,
            rotateX: 5,
            backgroundColor: 'rgba(255, 215, 0, 0.1)',
            borderColor: '#ffd700',
            transition: { type: 'spring' as const, stiffness: 400 }
        }
    };

    return (
        <div className="perspective-[1000px] fixed left-0 top-0 bottom-0 z-50">
            <motion.aside
                variants={containerVariants}
                animate={isCollapsed ? 'collapsed' : 'expanded'}
                style={{ transformStyle: 'preserve-3d' }}
                className="h-full bg-[#0a192f] border-r border-blue-500/20 shadow-[20px_0_50px_rgba(0,0,0,0.5)] flex flex-col pt-6 origin-left"
            >
                <div className="px-6 mb-10 flex items-center gap-4">
                    <div className="w-12 h-12 bg-gradient-to-br from-yellow-400 to-amber-600 rounded-xl shadow-[5px_5px_15px_rgba(251,191,36,0.2)] transform -rotate-3 hover:rotate-6 transition-transform flex items-center justify-center">
                        <span className="text-2xl font-black text-navy-900 text-slate-900">3D</span>
                    </div>
                    {!isCollapsed && (
                        <span className="text-xl font-black text-amber-50 uppercase tracking-tighter">DepthUI</span>
                    )}
                </div>

                <nav className="flex-1 px-4 space-y-4">
                    {navigationItems.map((item) => (
                        <motion.a
                            href={item.path}
                            key={item.id}
                            whileHover="hover"
                            variants={itemVariants}
                            className="flex items-center gap-4 p-3 rounded-xl cursor-pointer border border-white/5 text-slate-300 transform-gpu group block"
                            style={{ transformStyle: 'preserve-3d' }}
                        >
                            <div className="text-amber-400 group-hover:drop-shadow-[0_0_8px_rgba(251,191,36,0.6)]">
                                {item.icon}
                            </div>
                            {!isCollapsed && (
                                <span className="text-sm font-bold tracking-tight transform-gpu translate-z-10">{item.label}</span>
                            )}
                        </motion.a>
                    ))}

                    <div className="h-px bg-white/10 mx-4 my-8" />

                    {secondaryItems.map((item) => (
                        <motion.a
                            href={item.path}
                            key={item.id}
                            whileHover={{ scale: 1.05, x: 5 }}
                            className="flex items-center gap-4 p-3 rounded-xl cursor-pointer text-slate-500 hover:text-slate-200 transition-all font-bold text-sm block"
                        >
                            {item.icon}
                            {!isCollapsed && <span>{item.label}</span>}
                        </motion.a>
                    ))}
                </nav>

                <div className="p-6">
                    <div className={`p-4 bg-white/5 rounded-2xl border border-white/10 shadow-inner flex items-center gap-4 ${isCollapsed ? 'justify-center' : ''}`}>
                        <div className="relative">
                            <img src={currentUser.avatar} alt="User" className="w-10 h-10 rounded-xl border border-amber-400/50" />
                            {!isCollapsed && <div className="absolute -top-1 -right-1 w-3 h-3 bg-amber-400 rounded-full border-2 border-[#0a192f]" />}
                        </div>
                        {!isCollapsed && (
                            <div className="flex-1 overflow-hidden">
                                <p className="text-sm font-bold text-white truncate">{currentUser.name}</p>
                                <p className="text-[10px] text-amber-400/50 font-black tracking-widest uppercase truncate">{currentUser.role}</p>
                            </div>
                        )}
                    </div>
                </div>

                <button
                    onClick={() => setIsCollapsed(!isCollapsed)}
                    className="absolute top-1/2 -right-4 w-8 h-8 bg-amber-500 text-slate-900 rounded-full flex items-center justify-center shadow-[0_0_20px_rgba(245,158,11,0.4)] hover:scale-110 active:scale-95 transition-all z-50 transform -translate-y-1/2"
                >
                    {isCollapsed ? <ChevronRightIcon className="w-5 h-5" strokeWidth={3} /> : <ChevronLeftIcon className="w-5 h-5" strokeWidth={3} />}
                </button>
            </motion.aside>
        </div>
    );
};

export default ThreeDDepth;
