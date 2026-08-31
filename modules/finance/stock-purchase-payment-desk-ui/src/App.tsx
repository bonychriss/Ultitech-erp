import { readPoIdFromUrl } from './navigation';
import DeskListPage from './pages/DeskListPage';
import PoViewPage from './pages/PoViewPage';

export default function App() {
  const poId = readPoIdFromUrl();
  if (poId) {
    return <PoViewPage poId={poId} />;
  }

  return <DeskListPage />;
}
