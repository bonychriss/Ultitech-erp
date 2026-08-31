import React, { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { SidebarProvider } from './context/SidebarContext'
import { SidebarManager } from './components/Sidebar/SidebarManager'
import './index.css'

// Wrapper to provide context just for the Sidebar
const SidebarEmbed = () => {
    return (
        <SidebarProvider>
            <SidebarManager />
        </SidebarProvider>
    )
}

// Mount to a specific div ID that we will add to the PHP pages
const rootElement = document.getElementById('sidebar-root');
if (rootElement) {
    createRoot(rootElement).render(
        <StrictMode>
            <SidebarEmbed />
        </StrictMode>
    )
}
