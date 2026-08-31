import { useCallback, useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import PettyCashPageShell from '../components/PettyCashPageShell.jsx';
import { createCategory, fetchCategories } from '../api/pettyCashDesk.js';

export default function PettyCashCategoriesPage() {
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchCategories();
      setCategories(data.categories || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load categories.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function onCreate(event) {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      await createCategory(name.trim());
      setName('');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create category.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <PettyCashPageShell title="Categories">
      {error ? <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div> : null}
      <form className="exp-create-card" onSubmit={onCreate} style={{ marginBottom: '1rem' }}>
        <label className="exp-create-field">
          <span>New category name</span>
          <input type="text" required value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <button type="submit" className="exp-desk-btn exp-desk-btn-primary" disabled={busy}>Add category</button>
      </form>
      {loading ? (
        <div className="exp-desk-loading"><Loader2 className="exp-desk-boot-spinner" /><span>Loading...</span></div>
      ) : (
        <section className="exp-desk-results">
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Code</th>
                  <th>Vouchers</th>
                </tr>
              </thead>
              <tbody>
                {categories.map((c) => (
                  <tr key={c.id || c.name}>
                    <td>{c.name}</td>
                    <td>{c.code || '—'}</td>
                    <td>{c.voucher_count ?? 0}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </PettyCashPageShell>
  );
}
