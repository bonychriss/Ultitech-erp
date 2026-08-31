import { useState, useEffect } from 'react';
import { fetchStats, fetchExpenses, deleteExpense, fetchAccounts } from '../api';
import {
    Plus, Search, Filter, Download,
    CircleDollarSign, Calendar, Clock, Receipt,
    ChevronDown, ChevronUp, LayoutDashboard, UserCircle, LogOut,
    TrendingUp, PieChart as PieChartIcon, Eye, EyeOff
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, PieChart, Pie, Cell, Legend } from 'recharts';

export default function Dashboard() {
    const [stats, setStats] = useState(null);
    const [expenses, setExpenses] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({ search: '', status: '', category: '' });
    const [pagination, setPagination] = useState({ page: 1, total_pages: 1 });
    const [accounts, setAccounts] = useState([]);
    const [selectedMonth, setSelectedMonth] = useState(new Date().toISOString().slice(0, 7)); // YYYY-MM
    const [showTrends, setShowTrends] = useState(true);

    useEffect(() => {
        loadData();
        fetchAccounts().then(setAccounts).catch(console.error);
    }, [filters, pagination.page, selectedMonth]);

    async function loadData() {
        setLoading(true);
        try {
            const [statsData, expenseData] = await Promise.all([
                fetchStats(selectedMonth),
                fetchExpenses(pagination.page, { ...filters, month: selectedMonth })
            ]);
            setStats(statsData);
            setExpenses(Array.isArray(expenseData.data) ? expenseData.data : []);
            if (expenseData.pagination) {
                setPagination(expenseData.pagination);
            }
        } catch (err) {
            console.error(err);
            setExpenses([]);
        } finally {
            setLoading(false);
        }
    }

    const handleSearch = (e) => {
        setFilters({ ...filters, search: e.target.value });
        setPagination({ ...pagination, page: 1 });
    };

    return (
        <div className="w-full pl-6 pr-4 sm:pl-12 sm:pr-8 py-6 max-w-[1600px] mx-auto bg-[#f8f9fa] min-h-screen">
            {/* Top Navigation & Context Header */}
            <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
                <div className="flex items-center gap-4">
                    <button className="hamburger-menu p-2 bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 rounded-lg cursor-pointer transition-all shadow-none hover:shadow-sm" id="sidebarToggle" title="Toggle Sidebar"
                        onClick={() => {
                            if (window.innerWidth >= 897) {
                                document.body.classList.toggle('sidebar-collapsed');
                            } else {
                                window.toggleHeaderMenu?.();
                            }
                        }}>
                        <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" strokeWidth="2.5" fill="none" strokeLinecap="round" strokeLinejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                    </button>
                    <div>
                        <h1 className="text-3xl font-semibold text-gray-900 tracking-tight">Expenses Dashboard</h1>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <div className="relative group min-w-[160px]">
                        <input
                            type="month"
                            className="bg-white border border-gray-200 text-gray-700 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block w-full pl-4 pr-10 py-2.5 transition-all shadow-sm group-hover:border-gray-300 outline-none"
                            value={selectedMonth}
                            onChange={(e) => setSelectedMonth(e.target.value)}
                        />
                        <Calendar className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-gray-500 transition-colors pointer-events-none" size={18} />
                    </div>

                    <div className="flex items-center bg-white border border-gray-100 p-1 rounded-2xl shadow-sm">
                        <button
                            onClick={() => setShowTrends(!showTrends)}
                            className={`btn !mb-0 !mr-0 gap-2 px-4 py-2 border-none shadow-none ${showTrends ? 'btn-secondary' : 'btn-primary'}`}
                            title={showTrends ? "Hide Analysis" : "Show Analysis"}
                        >
                            {showTrends ? <EyeOff size={16} /> : <Eye size={16} />}
                            <span className="text-sm font-semibold">{showTrends ? 'Hide' : 'Trends'}</span>
                        </button>
                        <div className="w-px h-6 bg-gray-100 mx-1"></div>
                        <Link to="/create" className="btn btn-primary !mb-0 !mr-0 gap-2 px-4 py-2 border-none shadow-none">
                            <Plus size={18} />
                            <span className="text-sm font-semibold">Record</span>
                        </Link>
                    </div>
                </div>
            </div>

            {/* Stats Grid */}
            {stats && (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <StatCard
                        label="Total Volume"
                        value={`TSh ${stats.total_volume ? stats.total_volume.toLocaleString() : 0}`}
                        sub="Cumulative approved expenses"
                        color="text-blue-600"
                        icon={<CircleDollarSign size={24} />}
                    />
                    <StatCard
                        label="Monthly Spend"
                        value={`TSh ${stats.spend_month ? stats.spend_month.toLocaleString() : 0}`}
                        sub={`${new Date(selectedMonth).toLocaleDateString('default', { month: 'long', year: 'numeric' })} tracking`}
                        color="text-emerald-600"
                        icon={<Calendar size={24} />}
                    />
                    <StatCard
                        label="Pending Approval"
                        value={stats.pending_count || 0}
                        sub="Action required by finance"
                        color="text-amber-600"
                        icon={<Clock size={24} />}
                    />
                    <StatCard
                        label="Tax Tracked"
                        value={`TSh ${stats.total_tax ? stats.total_tax.toLocaleString() : 0}`}
                        sub="Recoverable input VAT"
                        color="text-rose-600"
                        icon={<Receipt size={24} />}
                    />
                </div>
            )}

            {/* Visualization Grid */}
            {stats && stats.trends && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">

                    {/* Main Trend Chart */}
                    {showTrends && (
                        <div className="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm lg:col-span-2 animate-in fade-in slide-in-from-top-4 duration-500">
                            <div className="flex items-center justify-between mb-4">
                                <div className="flex-1">
                                    <h3 className="text-sm font-bold text-gray-900">Spending Trends</h3>
                                    <p className="text-[10px] text-gray-400 font-medium uppercase tracking-wider">Monthly Category Distribution</p>
                                </div>
                                <div className="bg-gray-50 p-1.5 rounded-lg text-gray-400">
                                    <TrendingUp size={16} />
                                </div>
                            </div>

                            <div className="h-[200px] w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={stats.trends}>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f0f0f0" />
                                        <XAxis dataKey="name" axisLine={false} tickLine={false} tick={{ fill: '#94a3b8', fontSize: 13 }} dy={10} />
                                        <YAxis axisLine={false} tickLine={false} tick={{ fill: '#94a3b8', fontSize: 11 }} tickFormatter={val => `TSh ${val / 1000}k`} />
                                        <Tooltip
                                            cursor={{ fill: '#f8fafc' }}
                                            contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 15px -3px rgba(0,0,0,0.1)' }}
                                            formatter={(value) => [`TSh ${value.toLocaleString()}`, 'Spent']}
                                        />
                                        <Bar dataKey="amount" fill="#1a73e8" radius={[6, 6, 0, 0]} barSize={48} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                    )}

                    {/* Breakdown Charts */}
                    <div className="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm">
                        <div className="flex items-center justify-between mb-2">
                            <h3 className="text-sm font-bold text-gray-900">Category Breakdown</h3>
                            <PieChartIcon className="text-gray-400" size={16} />
                        </div>
                        <div className="h-[130px] w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie data={stats.by_category} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={25} outerRadius={50} paddingAngle={4} stroke="none" label={({ name }) => name}>
                                        {stats.by_category?.map((entry, index) => (
                                            <Cell key={`cell-${index}`} fill={['#1a73e8', '#34a853', '#f9ab00', '#ea4335', '#a142f4'][index % 5]} />
                                        ))}
                                    </Pie>
                                    <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 15px -3px rgba(0,0,0,0.1)' }} formatter={(value) => `TSh ${value.toLocaleString()}`} />
                                    <Legend verticalAlign="bottom" height={36} />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                    </div>

                    <div className="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm">
                        <div className="flex items-center justify-between mb-2">
                            <h3 className="text-sm font-bold text-gray-900">Approval Status</h3>
                            <Clock className="text-gray-400" size={16} />
                        </div>
                        <div className="h-[130px] w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie data={stats.by_status} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={25} outerRadius={50} paddingAngle={4} stroke="none" label>
                                        {stats.by_status?.map((entry, index) => (
                                            <Cell key={`cell-${index}`} fill={entry.name === 'approved' ? '#34a853' : entry.name === 'pending' ? '#f9ab00' : '#ea4335'} />
                                        ))}
                                    </Pie>
                                    <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 15px -3px rgba(0,0,0,0.1)' }} />
                                    <Legend verticalAlign="bottom" height={36} />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                    </div>
                </div>
            )}

            {/* List Section */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="p-6 border-b border-gray-100 flex flex-col lg:flex-row gap-5 items-center bg-white">
                    <div className="flex-1 flex flex-col sm:flex-row gap-3 w-full">
                        <div className="relative flex-1">
                            <div className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                                <Search size={18} />
                            </div>
                            <input type="text" className="w-full bg-gray-50 border-none rounded-xl pl-11 pr-4 py-3 text-sm focus:ring-2 focus:ring-blue-100 outline-none transition-all" placeholder="Search Payee, Expense #..."
                                value={filters.search} onChange={handleSearch} />
                        </div>
                        <div className="flex gap-2">
                            <select className="bg-gray-50 border-none rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-100 outline-none cursor-pointer" value={filters.status} onChange={e => setFilters({ ...filters, status: e.target.value })}>
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <select className="bg-gray-50 border-none rounded-xl px-4 py-4 text-sm focus:ring-2 focus:ring-blue-100 outline-none cursor-pointer max-w-[180px]" value={filters.category} onChange={e => setFilters({ ...filters, category: e.target.value })}>
                                <option value="">All Categories</option>
                                {accounts.map(acc => <option key={acc.id} value={acc.id}>{acc.name}</option>)}
                            </select>
                        </div>
                    </div>
                    <div className="flex gap-2 w-full lg:w-auto">
                        <button className="flex-1 lg:flex-none btn btn-secondary gap-2 px-5" onClick={loadData}>
                            <Filter size={15} />
                            <span>Apply</span>
                        </button>
                        <a href="../export.php" className="flex-1 lg:flex-none btn btn-secondary gap-2 px-5">
                            <Download size={15} />
                            <span>Export</span>
                        </a>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead>
                            <tr className="bg-gray-50/50">
                                <th className="text-left py-4 px-6 text-xs font-bold text-gray-400 uppercase tracking-widest">Date</th>
                                <th className="text-left py-4 px-6 text-xs font-bold text-gray-400 uppercase tracking-widest">Reference</th>
                                <th className="text-left py-4 px-6 text-xs font-bold text-gray-400 uppercase tracking-widest">Payee</th>
                                <th className="text-left py-4 px-6 text-xs font-bold text-gray-400 uppercase tracking-widest">Category</th>
                                <th className="text-right py-4 px-6 text-xs font-bold text-gray-400 uppercase tracking-widest">Amount</th>
                                <th className="text-center py-4 px-6 text-xs font-bold text-gray-400 uppercase tracking-widest w-[140px]">Status</th>
                                <th className="text-center py-4 px-6 text-xs font-bold text-gray-400 uppercase tracking-widest w-[100px]">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {expenses.length > 0 ? expenses.map(exp => (
                                <tr key={exp.id} className="hover:bg-gray-50/50 transition-colors group">
                                    <td className="py-5 px-6 whitespace-nowrap">
                                        <div className="text-sm font-medium text-gray-900">{new Date(exp.date).toLocaleDateString('default', { day: '2-digit', month: 'short' })}</div>
                                        <div className="text-xs text-gray-400 mt-0.5">{new Date(exp.date).getFullYear()}</div>
                                    </td>
                                    <td className="py-5 px-6 whitespace-nowrap">
                                        <div className="text-sm font-semibold text-blue-600 group-hover:underline cursor-pointer">{exp.expense_number}</div>
                                    </td>
                                    <td className="py-5 px-6">
                                        <div className="text-sm font-medium text-gray-900">{exp.payee}</div>
                                    </td>
                                    <td className="py-5 px-6">
                                        <div className="text-sm text-gray-600">{exp.category_name}</div>
                                    </td>
                                    <td className="py-5 px-6 text-right">
                                        <div className="text-sm font-bold text-gray-900">
                                            {exp.currency_code} {parseFloat(exp.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </div>
                                    </td>
                                    <td className="py-5 px-6 text-center">
                                        <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-bold tracking-wide uppercase border ${exp.status === 'approved' ? 'bg-emerald-50 text-emerald-600 border-emerald-100' :
                                            exp.status === 'pending' ? 'bg-amber-50 text-amber-600 border-amber-100' :
                                                'bg-rose-50 text-rose-600 border-rose-100'
                                            }`}>
                                            {exp.status}
                                        </span>
                                    </td>
                                    <td className="py-5 px-6 text-center">
                                        <Link to={`/view/${exp.id}`} className="text-gray-400 hover:text-blue-600 transition-colors">
                                            <LayoutDashboard size={18} />
                                        </Link>
                                    </td>
                                </tr>
                            )) : (
                                <tr>
                                    <td colSpan="7" className="text-center py-16">
                                        <div className="flex flex-col items-center">
                                            <div className="bg-gray-50 p-4 rounded-full mb-3">
                                                <Search className="text-gray-300" size={32} />
                                            </div>
                                            <p className="text-gray-500 font-medium">No expenses found for this selection</p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

function StatCard({ label, value, sub, color, icon }) {
    return (
        <div className="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all group overflow-hidden relative">
            <div className="flex items-start justify-between relative z-10">
                <div>
                    <span className="text-sm font-bold text-gray-400 uppercase tracking-widest">{label}</span>
                    <div className={`text-2xl font-black mt-2 tracking-tight ${color}`}>{value}</div>
                    <div className="text-xs text-gray-400 font-medium mt-1.5 flex items-center gap-1.5">
                        <Clock size={12} className="opacity-60" />
                        {sub}
                    </div>
                </div>
                <div className={`${color} !bg-transparent p-2 rounded-xl transition-transform group-hover:scale-110 duration-300 opacity-80 group-hover:opacity-100`}>
                    {icon}
                </div>
            </div>
        </div>
    );
}
