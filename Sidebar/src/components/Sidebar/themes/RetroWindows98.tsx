import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';

const RetroWindows98: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    const containerVariants = {
        expanded: { width: '260px' },
        collapsed: { width: '80px' }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            className="fixed left-0 top-0 bottom-0 z-50 bg-[#c0c0c0] font-['MS_Sans_Serif',_sans-serif] border-t-2 border-l-2 border-white border-b-2 border-r-2 border-[#808080] shadow-[1px_1px_0_0_#000] flex flex-col"
        >
            {/* Title Bar Style Header */}
            <div className="m-1 bg-gradient-to-r from-[#000080] to-[#1084d0] px-2 py-1 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <div className="w-4 h-4 bg-gray-300 rounded-sm overflow-hidden p-0.5">
                        <div className="w-full h-full bg-blue-600 rounded-full" />
                    </div>
                    {!isCollapsed && (
                        <span className="text-white text-[11px] font-bold tracking-wide">Staff_Dashboard</span>
                    )}
                </div>
                {!isCollapsed && (
                    <div className="flex gap-0.5">
                        <div className="w-4 h-4 bg-[#c0c0c0] border-t border-l border-white border-b border-r border-[#808080] flex items-center justify-center text-xs p-0.5 leading-none shadow-[1px_1px_0_0_#000] cursor-pointer active:shadow-none active:translate-x-px active:translate-y-px text-black font-black">?</div>
                        <div className="w-4 h-4 bg-[#c0c0c0] border-t border-l border-white border-b border-r border-[#808080] flex items-center justify-center text-xs p-0.5 leading-none shadow-[1px_1px_0_0_#000] cursor-pointer active:shadow-none active:translate-x-px active:translate-y-px text-black font-black">X</div>
                    </div>
                )}
            </div>

            <nav className="flex-1 p-2 space-y-1 mt-2">
                {navigationItems.map((item) => (
                    <a
                        href={item.path}
                        key={item.id}
                        className="group flex items-center gap-3 p-1 cursor-pointer hover:bg-[#000080] hover:text-white transition-colors border border-transparent hover:border-t-white hover:border-l-white hover:border-b-[#808080] hover:border-r-[#808080] block"
                    >
                        <div className="w-8 h-8 flex items-center justify-center bg-gray-400/20 rounded shadow-[inset_1px_1px_2px_rgba(0,0,0,0.5)] group-hover:bg-[#1084d0]">
                            {item.icon}
                        </div>
                        {!isCollapsed && (
                            <span className="text-[12px] font-medium truncate">{item.label}</span>
                        )}
                    </a>
                ))}
            </nav>

            <div className="p-2 border-t border-[#808080]">
                <div className="bg-[#fff] border-t border-l border-[#808080] border-b border-r border-white shadow-[inset_1px_1px_0_#000] p-1 flex items-center gap-2">
                    <img src={currentUser.avatar} alt="Win98" className="w-8 h-8 grayscale contrast-125 border border-black" />
                    {!isCollapsed && (
                        <div className="text-[10px] leading-tight">
                            <p className="font-bold uppercase text-black">{currentUser.name}</p>
                            <p className="text-gray-600">Online</p>
                        </div>
                    )}
                </div>
            </div>

            <div
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="m-2 bg-[#c0c0c0] border-t border-l border-white border-b border-r border-[#808080] shadow-[1px_1px_0_0_#000] p-1 flex items-center justify-center cursor-pointer active:shadow-none active:translate-x-px active:translate-y-px"
            >
                <span className="text-[11px] font-bold text-black uppercase tracking-widest">{isCollapsed ? '>>' : 'Minimize'}</span>
            </div>
        </motion.aside>
    );
};

export default RetroWindows98;
