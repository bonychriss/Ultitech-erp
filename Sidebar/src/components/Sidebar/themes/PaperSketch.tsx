import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';

const PaperSketch: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: {
            width: '260px',
            skewY: 0,
        },
        collapsed: {
            width: '80px',
            skewY: [0, 1, -1, 0],
            transition: { duration: 0.5, repeat: Infinity, repeatType: 'reverse' as const }
        }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            className="fixed left-0 top-0 bottom-0 z-50 bg-[#fdfcf0] border-r-4 border-slate-900 flex flex-col p-6 shadow-[10px_0_0px_#94a3b8]"
            style={{
                backgroundImage: 'radial-gradient(#e5e7eb 0.5px, transparent 0.5px)',
                backgroundSize: '20px 20px',
                fontFamily: '"Architects Daughter", cursive'
            }}
        >
            <div className="mb-12 rotate-[-2deg]">
                <div className="p-4 border-2 border-dashed border-slate-400 rounded-lg">
                    <h2 className="text-2xl font-bold text-slate-900 border-b-4 border-slate-900 inline-block">SKETCH.it</h2>
                    {!isCollapsed && <p className="text-xs text-slate-500 mt-1 italic">v_0.01_final</p>}
                </div>
            </div>

            <nav className="flex-1 space-y-6">
                {navigationItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover={{ scale: 1.05, rotate: 1 }}
                        className="flex items-center gap-6 cursor-pointer group hover:bg-slate-900 hover:text-white transition-all p-2 rounded-lg border-2 border-transparent hover:border-slate-400 block"
                    >
                        <div className="text-slate-900 group-hover:text-white transition-colors rotate-3 group-hover:rotate-0">
                            {item.icon}
                        </div>
                        {!isCollapsed && (
                            <span className="text-lg font-bold tracking-tight">{item.label}</span>
                        )}
                        {!isCollapsed && item.badge && (
                            <span className="ml-auto text-xs px-2 py-1 bg-yellow-200 text-black border border-black rotate-[-5deg] transform transform-gpu font-black">
                                {item.badge}
                            </span>
                        )}
                    </motion.a>
                ))}

                <div className="py-4 font-black text-slate-300 italic"> {">"} - - - - - {"<"} </div>

                {secondaryItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover={{ x: 5 }}
                        className="flex items-center gap-6 cursor-pointer text-slate-500 hover:text-slate-900 font-bold block"
                    >
                        {item.icon}
                        {!isCollapsed && <span>{item.label}</span>}
                    </motion.a>
                ))}
            </nav>

            <div className="mt-auto pt-8 border-t-2 border-slate-200 border-dashed">
                <div className={`p-4 border-2 border-slate-900 flex items-center gap-4 bg-white shadow-[4px_4px_0px_#000] ${isCollapsed ? 'justify-center' : ''}`}>
                    <img
                        src={currentUser.avatar}
                        alt="Sketch"
                        className="w-10 h-10 rounded-sm border-2 border-slate-900 p-0.5 grayscale"
                    />
                    {!isCollapsed && (
                        <div className="flex-1 overflow-hidden leading-tight">
                            <p className="text-sm font-black text-slate-900 truncate">{currentUser.name}</p>
                            <p className="text-xs text-slate-500 font-bold truncate tracking-widest uppercase">{currentUser.role}</p>
                        </div>
                    )}
                </div>
            </div>

            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute top-1/2 -right-4 w-10 h-10 bg-white border-4 border-slate-900 flex items-center justify-center text-slate-900 hover:bg-slate-900 hover:text-white shadow-[4px_4px_0px_#000] -translate-y-1/2 transition-all font-black text-xl z-[100]"
            >
                {isCollapsed ? '?' : '!'}
            </button>
        </motion.aside>
    );
};

export default PaperSketch;
