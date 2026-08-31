import PettyCashPageShell from '../components/PettyCashPageShell.jsx';
import PettyCashDeskPage from './PettyCashDeskPage.jsx';

export default function PettyCashVouchersListPage() {
  return (
    <PettyCashPageShell title="All vouchers">
      <PettyCashDeskPage fullList />
    </PettyCashPageShell>
  );
}
