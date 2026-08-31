import React, { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';

const Cursor = () => (
    <motion.span
        animate={{ opacity: [1, 0] }}
        transition={{ repeat: Infinity, duration: 0.8 }}
        className="inline-block w-2.5 h-4 bg-[#39ff14] ml-1 align-middle"
    />
);

const TerminalCLI: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();
    const [typedText, setTypedText] = useState('');

    const fullText = "SYSTEM_ROOT_v1.0.4";

    useEffect(() => {
        let i = 0;
        const interval = setInterval(() => {
            setTypedText(fullText.slice(0, i));
            i++;
            if (i > fullText.length) clearInterval(interval);
        }, 100);
        return () => clearInterval(interval);
    }, []);

    return (
        <motion.aside
            animate={{ width: isCollapsed ? '80px' : '260px' }}
            className="fixed left-0 top-0 bottom-0 z-50 bg-black flex flex-col font-['Courier_New',_Courier,_monospace] border-r-2 border-[#39ff14]/30"
        >
            <div className="p-6 h-20 border-b border-[#39ff14]/20">
                <div className="text-[#39ff14] text-sm font-bold flex items-center">
                    {!isCollapsed ? (
                        <> {">"} {typedText}<Cursor /> </>
                    ) : (
                        <span className="text-xl">#</span>
                    )}
                </div>
            </div>

            <nav className="flex-1 p-4 space-y-3 overflow-x-hidden">
                {navigationItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover={{ x: 10, color: '#39ff14' }}
                        className="group flex items-center gap-4 py-2 cursor-pointer text-[#39ff14]/60 transition-colors uppercase tracking-widest text-xs block"
                    >
                        <div className="group-hover:scale-110 transition-transform">
                            [{item.icon}]
                        </div>
                        {!isCollapsed && (
                            <span className="font-bold underline-offset-4 decoration-dashed decoration-1 group-hover:underline">
                                {item.label}.exe
                            </span>
                        )}
                        {item.badge && !isCollapsed && (
                            <span className="ml-auto text-[10px] bg-[#39ff14]/10 px-1 border border-[#39ff14]/30">
                                STATUS: {item.badge}
                            </span>
                        )}
                    </motion.a>
                ))}

                <div className="py-4 text-[#39ff14]/10 font-black text-[8px] overflow-hidden whitespace-nowrap">
                    - - - - SECURE_MIGRATION_READY - - - -
                </div>

                {secondaryItems.map((item) => (
                    <a
                        href={item.path}
                        key={item.id}
                        className="flex items-center gap-4 py-1.5 cursor-pointer text-[#39ff14]/40 hover:text-[#39ff14] transition-all uppercase text-[10px] block"
                    >
                        {item.icon}
                        {!isCollapsed && <span>./{item.label}</span>}
                    </a>
                ))}
            </nav>

            <div className="p-6 border-t border-[#39ff14]/10 bg-black/50">
                <div className={`p-2 border border-[#39ff14]/20 flex items-center gap-4 ${isCollapsed ? 'justify-center' : ''}`}>
                    <div className="w-10 h-10 border border-[#39ff14] flex items-center justify-center overflow-hidden">
                        <span className="text-xs text-[#39ff14] font-black">LOGIN</span>
                    </div>
                    {!isCollapsed && (
                        <div className="flex-1 overflow-hidden leading-tight">
                            <p className="text-[11px] font-black text-[#39ff14] truncate">USER: {currentUser.name.toUpperCase().split(' ').join('_')}</p>
                            <p className="text-[9px] text-[#39ff14]/40 font-bold truncate">ROLE: {currentUser.role.toUpperCase()}</p>
                        </div>
                    )}
                </div>
            </div>

            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute bottom-6 -right-3 w-6 h-6 bg-black border border-[#39ff14] flex items-center justify-center text-[#39ff14] hover:bg-[#39ff14] hover:text-black transition-all z-[100]"
            >
                {isCollapsed ? '+' : '-'}
            </button>
        </motion.aside>
    );
};

export default TerminalCLI;
