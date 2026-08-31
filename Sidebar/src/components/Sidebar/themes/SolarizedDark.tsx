import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';

const SolarizedDark: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    // Solarized Color Palette
    const base03 = '#002b36';
    const base02 = '#073642';
    const base01 = '#586e75';
    const base0 = '#839496';
    const base1 = '#93a1a1';
    const yellow = '#b58900';
    const blue = '#268bd2';
    const cyan = '#2aa198';

    const containerVariants = {
        expanded: { width: '260px' },
        collapsed: { width: '80px' }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            className="fixed left-0 top-0 bottom-0 z-50 flex flex-col border-r shadow-2xl"
            style={{ backgroundColor: base03, borderColor: base02 }}
        >
            <div className="p-8 pb-12 flex items-center gap-4">
                <div className="w-10 h-10 rounded-full flex items-center justify-center" style={{ backgroundColor: yellow }}>
                    <div className="w-4 h-4 rounded-full border-2 border-base03 animate-spin duration-1000" />
                </div>
                {!isCollapsed && (
                    <span className="text-xl font-bold tracking-tight uppercase" style={{ color: base1 }}>Solarized</span>
                )}
            </div>

            <nav className="flex-1 px-4 space-y-1">
                {navigationItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover={{ backgroundColor: base02, x: 5 }}
                        className="flex items-center gap-5 p-3.5 rounded-xl cursor-pointer transition-all group block"
                        style={{ color: base0 }}
                    >
                        <div className="transition-colors group-hover:scale-110" style={{ color: blue }}>
                            {item.icon}
                        </div>
                        {!isCollapsed && (
                            <span className="text-sm font-semibold tracking-wide group-hover:text-base1">{item.label}</span>
                        )}
                    </motion.a>
                ))}

                <div className="py-8 mx-4">
                    <div className="h-px opacity-20" style={{ backgroundColor: base01 }} />
                </div>

                {secondaryItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover={{ backgroundColor: base02 }}
                        className="flex items-center gap-5 p-3 rounded-xl cursor-pointer transition-all group block"
                        style={{ color: base01 }}
                    >
                        <div className="group-hover:text-cyan transition-colors">{item.icon}</div>
                        {!isCollapsed && (
                            <span className="text-sm font-bold uppercase tracking-widest">{item.label}</span>
                        )}
                    </motion.a>
                ))}
            </nav>

            <div className="p-6 border-t" style={{ borderColor: base02 }}>
                <div className={`flex items-center gap-4 p-3 rounded-2xl ${isCollapsed ? 'justify-center' : ''}`} style={{ backgroundColor: '#07364244' }}>
                    <img
                        src={currentUser.avatar}
                        alt="User"
                        className="w-10 h-10 rounded-full border-2"
                        style={{ borderColor: cyan }}
                    />
                    {!isCollapsed && (
                        <div className="flex-1 overflow-hidden">
                            <p className="text-sm font-bold truncate" style={{ color: base1 }}>{currentUser.name}</p>
                            <p className="text-[10px] uppercase font-black" style={{ color: base01 }}>{currentUser.role}</p>
                        </div>
                    )}
                </div>
            </div>

            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute top-1/2 -right-4 w-8 h-8 rounded-full flex items-center justify-center transition-all hover:scale-110 shadow-lg -translate-y-1/2"
                style={{ backgroundColor: blue, color: base03 }}
            >
                <span className="font-bold text-xl">{isCollapsed ? '\u00bb' : '\u00ab'}</span>
            </button>
        </motion.aside>
    );
};

export default SolarizedDark;
