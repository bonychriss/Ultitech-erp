import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { fetchAccounts } from '../api';

const API_BASE = './api';

export default function ExpenseForm() {
    const navigate = useNavigate();
    const { id } = useParams();
    const isEdit = !!id;

    const [form, setForm] = useState({
        date: new Date().toISOString().split('T')[0],
        payee: '',
        account_id: '',
        payment_method: 'cash',
        currency_code: 'TSh',
        amount: '',
        tax_amount: '0.00',
        description: '',
        attachment: null
    });
    const [accounts, setAccounts] = useState([]);
    const [loading, setLoading] = useState(false);
    const [payees, setPayees] = useState([]);

    useEffect(() => {
        fetchAccounts().then(setAccounts).catch(console.error);

        fetch(`${API_BASE}/payees.php`).then(r => r.json()).then(setPayees).catch(console.error);

        if (isEdit) {
            fetch(`${API_BASE}/expenses.php?search=${id}`)
                .then(r => r.json())
                .then(res => {
                    const found = res.data.find(e => e.id == id);
                    if (found) {
                        setForm({
                            ...found,
                            date: found.date.split(' ')[0],
                        });
                    }
                });
        }
    }, [id]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        try {
            const method = isEdit ? 'PUT' : 'POST';
            const body = { ...form };
            if (isEdit) body.id = id;

            const res = await fetch(`${API_BASE}/expenses.php`, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });

            if (!res.ok) throw new Error('Action failed');
            navigate('/');
        } catch (err) {
            alert(err.message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="container">
            <div className="page-header">
                <div className="page-title">
                    <h1>{isEdit ? 'Edit Expense' : 'Record New Expense'}</h1>
                </div>
            </div>

            <div className="bg-white rounded-lg border border-gray-200 shadow-sm p-6 max-w-4xl mx-auto">
                <form onSubmit={handleSubmit}>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div className="form-group space-y-1.5">
                            <label className="block text-sm font-medium text-gray-700">Date <span className="text-red-500">*</span></label>
                            <input type="date" className="form-control" required
                                value={form.date} onChange={e => setForm({ ...form, date: e.target.value })} />
                        </div>
                        <div className="form-group space-y-1.5">
                            <label className="block text-sm font-medium text-gray-700">Payee <span className="text-red-500">*</span></label>
                            <input type="text" list="payee_list" className="form-control" required
                                value={form.payee} onChange={e => setForm({ ...form, payee: e.target.value })} />
                            <datalist id="payee_list">
                                {payees.map(p => <option key={p.id} value={p.name} />)}
                            </datalist>
                        </div>
                        <div className="form-group space-y-1.5">
                            <label className="block text-sm font-medium text-gray-700">Category <span className="text-red-500">*</span></label>
                            <select className="form-control" required
                                value={form.account_id} onChange={e => setForm({ ...form, account_id: e.target.value })}>
                                <option value="">Select Category</option>
                                {accounts.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                        </div>

                        <div className="form-group space-y-1.5">
                            <label className="block text-sm font-medium text-gray-700">Payment Method <span className="text-red-500">*</span></label>
                            <select className="form-control" required
                                value={form.payment_method} onChange={e => setForm({ ...form, payment_method: e.target.value })}>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                        <div className="form-group space-y-1.5">
                            <label className="block text-sm font-medium text-gray-700">Currency <span className="text-red-500">*</span></label>
                            <select className="form-control" required
                                value={form.currency_code} onChange={e => setForm({ ...form, currency_code: e.target.value })}>
                                <option value="TSh">TSh</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                                <option value="GBP">GBP</option>
                            </select>
                        </div>
                        <div className="form-group space-y-1.5">
                            <label className="block text-sm font-medium text-gray-700">Amount (Net) <span className="text-red-500">*</span></label>
                            <input type="number" step="0.01" className="form-control" required
                                value={form.amount} onChange={e => setForm({ ...form, amount: e.target.value })} />
                        </div>
                        <div className="form-group space-y-1.5">
                            <label className="block text-sm font-medium text-gray-700">Tax Amount</label>
                            <input type="number" step="0.01" className="form-control"
                                value={form.tax_amount} onChange={e => setForm({ ...form, tax_amount: e.target.value })} />
                        </div>
                    </div>

                    <div className="form-group space-y-1.5 mt-6">
                        <label className="block text-sm font-medium text-gray-700">Description</label>
                        <textarea className="form-control w-full min-h-[80px]" rows="2"
                            value={form.description} onChange={e => setForm({ ...form, description: e.target.value })}></textarea>
                    </div>

                    <div className="flex justify-end gap-3 mt-8 pt-6 border-t border-gray-100">
                        <button type="button" className="btn btn-secondary px-6" onClick={() => navigate('/')}>Cancel</button>
                        <button type="submit" className="btn btn-primary px-8" disabled={loading}>{loading ? 'Saving...' : 'Save Expense'}</button>
                    </div>
                </form>
            </div>
        </div>
    );
}
