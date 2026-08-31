import React from 'react';
import {
    HomeIcon,
    ChartBarIcon,
    UsersIcon,
    DocumentTextIcon,
    Cog6ToothIcon,
    ArrowRightOnRectangleIcon,
    MagnifyingGlassIcon,
    ShoppingBagIcon,
    EnvelopeIcon
} from '@heroicons/react/24/outline';
import { NavItem, UserProfile } from '../types/sidebar';

export const navigationItems: NavItem[] = [
    { id: 'dashboard', label: 'Dashboard', icon: <HomeIcon className="w-6 h-6" />, path: '/' },
    { id: 'analytics', label: 'Analytics', icon: <ChartBarIcon className="w-6 h-6" />, path: '/analytics', badge: 'New' },
    { id: 'users', label: 'Team', icon: <UsersIcon className="w-6 h-6" />, path: '/users' },
    { id: 'orders', label: 'Orders', icon: <ShoppingBagIcon className="w-6 h-6" />, path: '/orders', badge: 5 },
    { id: 'documents', label: 'Reports', icon: <DocumentTextIcon className="w-6 h-6" />, path: '/documents' },
    { id: 'messages', label: 'Messages', icon: <EnvelopeIcon className="w-6 h-6" />, path: '/messages' },
];

export const secondaryItems: NavItem[] = [
    { id: 'settings', label: 'Settings', icon: <Cog6ToothIcon className="w-6 h-6" />, path: '/settings' },
    { id: 'logout', label: 'Logout', icon: <ArrowRightOnRectangleIcon className="w-6 h-6" />, path: '/logout' },
];

export const currentUser: UserProfile = [
    {
        name: "Alex Johnson",
        role: "Senior Administrator",
        avatar: "https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1\u0026auto=format\u0026fit=facearea\u0026facepad=2\u0026w=256\u0026h=256\u0026q=80"
    }
][0]; 
