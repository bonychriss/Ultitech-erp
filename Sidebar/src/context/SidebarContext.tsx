import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { SidebarThemeId, NavItem } from '../types/sidebar';
import { navigationItems as defaultItems } from '../data/sidebarItems';

interface SidebarContextType {
    theme: SidebarThemeId;
    setTheme: (theme: SidebarThemeId) => void;
    isCollapsed: boolean;
    setIsCollapsed: (collapsed: boolean) => void;
    isMobileOpen: boolean;
    setIsMobileOpen: (open: boolean) => void;
    navigationItems: NavItem[];
    setNavigationItems: (items: NavItem[]) => void;
}

const SidebarContext = createContext<SidebarContextType | undefined>(undefined);

export const SidebarProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
    const [theme, setThemeState] = useState<SidebarThemeId>(() => {
        const saved = localStorage.getItem('sidebar-theme');
        return (saved as SidebarThemeId) || 'glassmorphism-floating';
    });

    const [isCollapsed, setIsCollapsed] = useState(() => {
        const saved = localStorage.getItem('sidebar-collapsed');
        return saved === 'true';
    });

    const [isMobileOpen, setIsMobileOpen] = useState(false);

    // Default to the static items initially
    const [navigationItems, setNavigationItems] = useState<NavItem[]>(defaultItems);

    const setTheme = (newTheme: SidebarThemeId) => {
        setThemeState(newTheme);
        localStorage.setItem('sidebar-theme', newTheme);
    };

    const handleSetIsCollapsed = (collapsed: boolean) => {
        setIsCollapsed(collapsed);
        localStorage.setItem('sidebar-collapsed', String(collapsed));
    };

    // Effect to sync CSS variable with state
    useEffect(() => {
        const width = isCollapsed ? '80px' : '260px';
        document.documentElement.style.setProperty('--sidebar-width', width);
        // Also add a class to body for legacy CSS targeting
        if (isCollapsed) {
            document.body.classList.add('sidebar-collapsed');
        } else {
            document.body.classList.remove('sidebar-collapsed');
        }
    }, [isCollapsed]);

    return (
        <SidebarContext.Provider
            value={{
                theme,
                setTheme,
                isCollapsed,
                setIsCollapsed: handleSetIsCollapsed,
                isMobileOpen,
                setIsMobileOpen,
                navigationItems,
                setNavigationItems
            }}
        >
            {children}
        </SidebarContext.Provider>
    );
};

export const useSidebar = () => {
    const context = useContext(SidebarContext);
    if (context === undefined) {
        throw new Error('useSidebar must be used within a SidebarProvider');
    }
    return context;
};
