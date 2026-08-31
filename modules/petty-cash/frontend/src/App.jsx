import { getPageKey } from './utils/format.js';
import PettyCashDeskPage from './pages/PettyCashDeskPage.jsx';
import PettyCashCreateVoucherPage from './pages/PettyCashCreateVoucherPage.jsx';
import PettyCashReplenishmentPage from './pages/PettyCashReplenishmentPage.jsx';
import PettyCashVoucherViewPage from './pages/PettyCashVoucherViewPage.jsx';
import PettyCashReportsPage from './pages/PettyCashReportsPage.jsx';
import PettyCashVouchersListPage from './pages/PettyCashVouchersListPage.jsx';
import PettyCashReplenishmentsListPage from './pages/PettyCashReplenishmentsListPage.jsx';
import PettyCashReplenishmentConfirmPage from './pages/PettyCashReplenishmentConfirmPage.jsx';
import PettyCashCategoriesPage from './pages/PettyCashCategoriesPage.jsx';

export default function App() {
  const page = getPageKey();

  switch (page) {
    case 'create-voucher':
      return <PettyCashCreateVoucherPage />;
    case 'replenishment':
      return <PettyCashReplenishmentPage />;
    case 'view-voucher':
      return <PettyCashVoucherViewPage />;
    case 'reports':
      return <PettyCashReportsPage />;
    case 'vouchers-list':
      return <PettyCashVouchersListPage />;
    case 'replenishments-list':
      return <PettyCashReplenishmentsListPage />;
    case 'replenishment-confirm':
      return <PettyCashReplenishmentConfirmPage />;
    case 'categories':
      return <PettyCashCategoriesPage />;
    case 'desk':
    default:
      return <PettyCashDeskPage />;
  }
}
