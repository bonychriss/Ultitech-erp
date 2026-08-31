const API_BASE = './api';

export async function fetchStats() {
    const res = await fetch(`${API_BASE}/stats.php`);
    if (!res.ok) throw new Error('Failed to fetch stats');
    return res.json();
}

export async function fetchExpenses(page = 1, filters = {}) {
    const params = new URLSearchParams({ page, ...filters });
    const res = await fetch(`${API_BASE}/expenses.php?${params}`);
    if (!res.ok) throw new Error('Failed to fetch expenses');
    return res.json();
}

export async function deleteExpense(id) {
    const res = await fetch(`${API_BASE}/expenses.php?id=${id}`, { method: 'DELETE' });
    if (!res.ok) throw new Error('Failed to delete expense');
    return res.json();
}

// Add more helpers as needed
export async function fetchAccounts() {
    const res = await fetch(`${API_BASE}/accounts.php`);
    if (!res.ok) {
        console.error("Failed to fetch accounts", res.status);
        return [];
    }
    const data = await res.json();
    return Array.isArray(data) ? data : [];
}
