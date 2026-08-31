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
    EnvelopeIcon,
    TruckIcon,
    TicketIcon,
    ClipboardDocumentListIcon,
    CurrencyDollarIcon,
    ArchiveBoxIcon,
    PresentationChartLineIcon,
    StarIcon,
    MapIcon,
    CalendarIcon
} from '@heroicons/react/24/outline';

const iconMap: Record<string, React.ElementType> = {
    'home': HomeIcon,
    'chart-bar': ChartBarIcon,
    'users': UsersIcon,
    'document-text': DocumentTextIcon,
    'cog': Cog6ToothIcon,
    'logout': ArrowRightOnRectangleIcon,
    'search': MagnifyingGlassIcon,
    'shopping-bag': ShoppingBagIcon,
    'envelope': EnvelopeIcon,
    'truck': TruckIcon,
    'ticket': TicketIcon,
    'clipboard': ClipboardDocumentListIcon,
    'currency': CurrencyDollarIcon,
    'archive': ArchiveBoxIcon,
    'presentation': PresentationChartLineIcon,
    'star': StarIcon,
    'map': MapIcon,
    'calendar': CalendarIcon
};

interface IconMapperProps {
    iconName: string;
    className?: string;
}

export const IconMapper: React.FC<IconMapperProps> = ({ iconName, className }) => {
    const IconComponent = iconMap[iconName] || HomeIcon;
    return <IconComponent className={className} />;
};
