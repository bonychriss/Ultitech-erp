import React, { Suspense, lazy } from 'react';
import { useSidebar } from '../../context/SidebarContext';
import { IconMapper } from './IconMapper';
import { ThemeSwitcher } from '../ThemeSwitcher';

// Lazy load themes to keep bundle size small
const themes = {
    'glassmorphism-floating': lazy(() => import('./themes/GlassmorphismFloating')),
    'neon-cyberpunk': lazy(() => import('./themes/NeonCyberpunk')),
    'minimalist-gradient': lazy(() => import('./themes/MinimalistGradient')),
    '3d-depth': lazy(() => import('./themes/ThreeDDepth')),
    'retro-windows-98': lazy(() => import('./themes/RetroWindows98')),
    'solarized-dark': lazy(() => import('./themes/SolarizedDark')),
    'material-you': lazy(() => import('./themes/MaterialYou')),
    'macos-big-sur': lazy(() => import('./themes/MacOSBigSur')),
    'terminal-cli': lazy(() => import('./themes/TerminalCLI')),
    'gaming-rgb': lazy(() => import('./themes/GamingRGB')),
    'paper-sketch': lazy(() => import('./themes/PaperSketch')),
    'holographic': lazy(() => import('./themes/Holographic')),
};

export const SidebarManager: React.FC = () => {
    const { theme, setNavigationItems } = useSidebar();

    // Check for window data on mount
    React.useEffect(() => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const win = window as any;
        if (typeof window !== 'undefined' && win.SIDEBAR_DATA) {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const mappedItems = win.SIDEBAR_DATA.map((item: any) => ({
                ...item,
                // If icon is a string, assume it's a key for IconMapper
                icon: typeof item.icon === 'string' ? <IconMapper iconName={item.icon} className="w-6 h-6" /> : item.icon
            }));
            setNavigationItems(mappedItems);
        }
    }, [setNavigationItems]);

    const ActiveSidebar = themes[theme] || themes['glassmorphism-floating'];

    return (
        <Suspense fallback={<div className="w-64 h-screen bg-gray-100 animate-pulse" />}>
            <ActiveSidebar />
            <ThemeSwitcher />
        </Suspense>
    );
};
