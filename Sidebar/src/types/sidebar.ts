import { ReactNode } from 'react';
import { MotionProps } from 'framer-motion';

export type SidebarThemeId =
    | 'glassmorphism-floating'
    | 'neon-cyberpunk'
    | 'minimalist-gradient'
    | '3d-depth'
    | 'retro-windows-98'
    | 'solarized-dark'
    | 'material-you'
    | 'macos-big-sur'
    | 'terminal-cli'
    | 'gaming-rgb'
    | 'paper-sketch'
    | 'holographic';

export interface SidebarColors {
    background: string;
    text: string;
    active: string;
    hover: string;
    border: string;
    accent?: string;
    shadow?: string;
}

export interface SidebarAnimations {
    entrance: MotionProps;
    exit: MotionProps;
    itemHover: MotionProps;
}

export interface SidebarTheme {
    id: SidebarThemeId;
    name: string;
    colors: SidebarColors;
    animations: SidebarAnimations;
    customCSS?: string;
    fontFamily?: string;
}

export interface NavItem {
    id: string;
    label: string;
    icon: ReactNode | string;
    path: string;
    badge?: string | number;
}

export interface UserProfile {
    name: string;
    role: string;
    avatar: string;
}
