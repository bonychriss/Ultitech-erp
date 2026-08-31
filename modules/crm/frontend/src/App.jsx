import CrmDeskPage from './pages/CrmDeskPage';
import CrmContactViewPage from './pages/CrmContactViewPage';
import CrmMarketPage from './pages/CrmMarketPage';
import CrmProspectsPage from './pages/CrmProspectsPage';
import { getBootData } from './api';

function currentTab() {
  try {
    return new URLSearchParams(window.location.search).get('tab') || '';
  } catch {
    return '';
  }
}

export default function App() {
  const boot = getBootData();

  if (boot.page === 'contact-view') {
    return <CrmContactViewPage />;
  }

  if (boot.page === 'market') {
    return <CrmMarketPage />;
  }

  if (boot.page === 'prospects' || currentTab() === 'prospects') {
    return <CrmProspectsPage />;
  }

  return <CrmDeskPage />;
}
