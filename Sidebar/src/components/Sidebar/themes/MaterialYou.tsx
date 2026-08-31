import React from 'react';
import { motion } from 'framer-motion';
import { useSidebar } from '../../../context/SidebarContext';
import { secondaryItems, currentUser } from '../../../data/sidebarItems';

const MaterialYou: React.FC = () => {
    const { isCollapsed, setIsCollapsed, navigationItems } = useSidebar();

    // Dynamic colors (simulated)
    const bg = '#fef7ff';
    const surface = '#f3edf7';
    const primary = '#6750a4';
    const primaryContainer = '#eaddff';
    const onPrimaryContainer = '#21005d';
    const outline = '#79747e';

    const containerVariants = {
        expanded: { width: '260px' },
        collapsed: { width: '80px' }
    };

    return (
        <motion.aside
            variants={containerVariants}
            animate={isCollapsed ? 'collapsed' : 'expanded'}
            transition={{ type: 'spring', damping: 25, stiffness: 200 }}
            className="fixed left-0 top-0 bottom-0 z-50 flex flex-col shadow-lg font-[Roboto,_sans-serif] px-4 py-8"
            style={{ backgroundColor: bg }}
        >
            <div className="flex items-center gap-4 mb-10 pl-2">
                <motion.div
                    whileTap={{ scale: 0.95 }}
                    className="w-12 h-12 rounded-[1.75rem] flex items-center justify-center transition-all shadow-md active:shadow-none"
                    style={{ backgroundColor: primaryContainer, color: onPrimaryContainer }}
                >
                    <span className="text-2xl font-bold">M</span>
                </motion.div>
                {!isCollapsed && (
                    <span className="text-xl font-medium text-slate-800 tracking-tight">Material You</span>
                )}
            </div>

            <nav className="flex-1 space-y-1">
                {navigationItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover={{ scale: 1.02 }}
                        whileTap={{ scale: 0.98 }}
                        className="flex items-center gap-4 px-4 py-3.5 rounded-[1.75rem] cursor-pointer transition-all relative group block"
                        style={{
                            backgroundColor: 'transparent',
                            color: '#49454f'
                        }}
                    >
                        <div className="z-10 group-hover:text-primary transition-colors">{item.icon}</div>
                        {!isCollapsed && (
                            <span className="text-sm font-medium z-10 group-hover:text-black transition-colors">{item.label}</span>
                        )}

                        {/* Ripple Surface Layer */}
                        <div className="absolute inset-0 rounded-[1.75rem] group-hover:bg-primary/5 transition-colors z-0" />

                        {item.badge && !isCollapsed && (
                            <span className="ml-auto text-xs font-bold px-3 py-1 rounded-full z-10" style={{ backgroundColor: primaryContainer, color: onPrimaryContainer }}>
                                {item.badge}
                            </span>
                        )}
                    </motion.a>
                ))}

                <div className="py-6 px-10">
                    <div className="h-px" style={{ backgroundColor: outline, opacity: 0.2 }} />
                </div>

                {secondaryItems.map((item) => (
                    <motion.a
                        href={item.path}
                        key={item.id}
                        whileHover={{ scale: 1.02 }}
                        className="flex items-center gap-4 px-4 py-3 rounded-[1.5rem] cursor-pointer group hover:bg-black/5 block"
                        style={{ color: outline }}
                    >
                        <div className="z-10">{item.icon}</div>
                        {!isCollapsed && (
                            <span className="text-sm font-bold tracking-wide z-10">{item.label}</span>
                        )}
                    </motion.a>
                ))}
            </nav>

            <div className="mt-auto">
                <div className={`p-2 rounded-[2rem] flex items-center gap-4 transition-all hover:bg-surface ${isCollapsed ? 'justify-center' : 'pl-3'}`}>
                    <img src={currentUser.avatar} alt="You" className="w-12 h-12 rounded-full" />
                    {!isCollapsed && (
                        <div className="flex-1 overflow-hidden">
                            <p className="text-sm font-bold text-slate-900 truncate">{currentUser.name}</p>
                            <p className="text-[11px] text-slate-500 font-medium truncate">{currentUser.role}</p>
                        </div>
                    )}
                </div>
            </div>

            <button
                onClick={() => setIsCollapsed(!isCollapsed)}
                className="absolute top-24 -right-3 w-8 h-8 rounded-2xl flex items-center justify-center transition-all shadow-xl hover:scale-110 active:scale-95 z-[100]"
                style={{ backgroundColor: surface, color: primary }}
            >
                <div className="w-1.5 h-1.5 rounded-full bg-current" />
            </button>
        </motion.aside>
    );
};

export default MaterialYou;
