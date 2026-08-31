const { useState, useEffect } = React;

const KPI_TONES = {
    blue: { icon: 'bg-blue-50 text-blue-600', ring: 'ring-blue-100' },
    indigo: { icon: 'bg-indigo-50 text-indigo-600', ring: 'ring-indigo-100' },
    green: { icon: 'bg-emerald-50 text-emerald-600', ring: 'ring-emerald-100' },
    violet: { icon: 'bg-violet-50 text-violet-600', ring: 'ring-violet-100' },
};

function formatMoney(amount) {
    const value = Number(amount) || 0;
    return 'TSh ' + value.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function formatDate(value) {
    if (!value) return '\u2014';
    const date = new Date(value + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return '\u2014';
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatPeriod(start, end) {
    if (!start || !end) return '';
    return formatDate(start) + ' \u2013 ' + formatDate(end);
}

function StatusBadge({ status }) {
    const normalized = String(status || '').toLowerCase();
    let classes = 'bg-slate-100 text-slate-600';
    if (normalized === 'paid') classes = 'bg-emerald-50 text-emerald-700';
    else if (normalized === 'sent') classes = 'bg-blue-50 text-blue-700';
    else if (normalized === 'overdue') classes = 'bg-red-50 text-red-700';
    else if (normalized === 'draft' || normalized === 'quotation') classes = 'bg-amber-50 text-amber-700';

    const label = normalized ? normalized.charAt(0).toUpperCase() + normalized.slice(1) : '\u2014';
    return (
        <span className={'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ' + classes}>
            {label}
        </span>
    );
}

function KpiCard({ icon, label, value, tone }) {
    const styles = KPI_TONES[tone] || KPI_TONES.blue;
    return (
        <div className={'bg-white rounded-xl border border-slate-200 shadow-sm p-3.5 ring-1 ' + styles.ring}>
            <div className="flex items-start gap-3">
                <span className={'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-sm ' + styles.icon}>
                    <i className={'fas ' + icon}></i>
                </span>
                <div className="min-w-0">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</p>
                    <p className="mt-0.5 text-base font-bold text-slate-900 tabular-nums">{value}</p>
                </div>
            </div>
        </div>
    );
}

function AiInsights({ insights, loading, source }) {
    const sourceLabel = source === 'ai' ? 'AI-powered' : 'Smart rules';

    return (
        <section className="rounded-2xl border border-slate-200 bg-gradient-to-b from-slate-50 to-white shadow-sm p-4">
            <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 text-xs">
                        <i className="fas fa-wand-magic-sparkles"></i>
                    </span>
                    AI Suggestions
                </h3>
                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">
                    {sourceLabel}
                </span>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div className="rounded-xl border border-emerald-100 bg-white p-3.5">
                    <h4 className="mb-2 flex items-center gap-1.5 text-xs font-bold text-emerald-700">
                        <i className="fas fa-circle-check"></i> Highlights
                    </h4>
                    <ul className="space-y-2 text-xs leading-relaxed text-slate-600">
                        {(insights.achievements || []).map((item, index) => (
                            <li key={'ach-' + index} className="flex gap-2">
                                <span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500"></span>
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="rounded-xl border border-amber-100 bg-white p-3.5">
                    <h4 className="mb-2 flex items-center gap-1.5 text-xs font-bold text-amber-700">
                        <i className="fas fa-lightbulb"></i> Recommendations
                    </h4>
                    <ul className="space-y-2 text-xs leading-relaxed text-slate-600">
                        {(insights.suggestions || []).map((item, index) => (
                            <li key={'sug-' + index} className="flex gap-2">
                                <span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
            {loading && (
                <div className="mt-3 flex items-center gap-2 text-xs text-slate-500" aria-live="polite">
                    <i className="fas fa-spinner fa-spin text-indigo-500"></i>
                    <span>Refreshing AI suggestions...</span>
                </div>
            )}
        </section>
    );
}

function DetailTable({ title, icon, count, total, columns, rows, emptyMessage, viewBase, idField, numberField, showStatus }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div className="border-b border-slate-100 px-4 py-2.5 flex flex-wrap items-baseline justify-between gap-2">
                <h5 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                    <span className="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100 text-slate-600 text-[11px]">
                        <i className={'fas ' + icon}></i>
                    </span>
                    {title}
                </h5>
                <span className="text-xs font-semibold text-slate-500">
                    {count.toLocaleString()}
                    <span className="mx-1.5 text-slate-300">{'\u00b7'}</span>
                    Total {formatMoney(total)}
                </span>
            </div>
            {rows.length === 0 ? (
                <p className="px-4 py-6 text-xs text-slate-500">{emptyMessage}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-xs">
                        <thead>
                            <tr className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                {columns.map((col) => (
                                    <th key={col.key} className={'px-4 py-2 ' + (col.align === 'end' ? 'text-right' : '')}>
                                        {col.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((row) => (
                                <tr key={row[idField]} className="hover:bg-slate-50/80 transition-colors">
                                    {columns.map((col) => {
                                        if (col.key === numberField) {
                                            return (
                                                <td key={col.key} className="px-4 py-2 font-semibold text-slate-900">
                                                    <a
                                                        href={viewBase + row[idField]}
                                                        className="text-blue-600 hover:text-blue-700 hover:underline"
                                                    >
                                                        {row[col.key]}
                                                    </a>
                                                </td>
                                            );
                                        }
                                        if (col.key === 'amount') {
                                            return (
                                                <td key={col.key} className="px-4 py-2 text-right font-semibold text-slate-900 tabular-nums">
                                                    {formatMoney(row.total_amount)}
                                                </td>
                                            );
                                        }
                                        if (col.type === 'date') {
                                            return <td key={col.key} className="px-4 py-2 text-slate-600">{formatDate(row[col.key])}</td>;
                                        }
                                        if (col.key === 'status') {
                                            return (
                                                <td key={col.key} className="px-4 py-2">
                                                    <StatusBadge status={row.status} />
                                                </td>
                                            );
                                        }
                                        return <td key={col.key} className="px-4 py-2 text-slate-700">{row[col.key]}</td>;
                                    })}
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="bg-slate-50 font-bold text-slate-900">
                                <td colSpan={columns.length - (showStatus ? 2 : 1)} className="px-4 py-2">
                                    Total
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">{formatMoney(total)}</td>
                                {showStatus ? <td className="px-4 py-2"></td> : null}
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </div>
    );
}

function RepDetailApp({ data }) {
    const [insights, setInsights] = useState(data.initialInsights || { achievements: [], suggestions: [] });
    const [insightSource, setInsightSource] = useState(data.initialInsights?.source || 'rules');
    const [insightsLoading, setInsightsLoading] = useState(true);

    useEffect(() => {
        const params = new URLSearchParams({
            module: 'analytics',
            user_id: String(data.userId || 0),
            rep_name: data.repName || '',
            start_date: data.startDate || '',
            end_date: data.endDate || '',
        });

        fetch(data.apis.aiInsights + '?' + params.toString(), { credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then((payload) => {
                if (payload && payload.success) {
                    setInsights({
                        achievements: payload.achievements || [],
                        suggestions: payload.suggestions || [],
                    });
                    setInsightSource(payload.source || 'rules');
                }
            })
            .catch(() => {})
            .finally(() => setInsightsLoading(false));
    }, [data]);

    const quoteCount = data.quotations.length;
    const invoiceCount = data.invoices.length;

    return (
        <div className="animate-fade-in space-y-4 max-w-[1400px] mx-auto text-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-bold tracking-tight text-slate-900 flex items-center gap-2">
                        <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600/10 text-indigo-600 text-sm">
                            <i className="fas fa-user-tie"></i>
                        </span>
                        {data.repName}
                    </h2>
                    <p className="mt-0.5 ml-10 text-xs font-medium text-slate-500">
                        Quotations &amp; invoices {'\u00b7'} {formatPeriod(data.startDate, data.endDate)}
                    </p>
                </div>
                <a
                    href={data.backUrl}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-bold text-slate-600 shadow-sm transition hover:bg-slate-50"
                >
                    <i className="fas fa-arrow-left"></i> Back to Sales Analytics
                </a>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <KpiCard icon="fa-file-lines" label="Quotations" value={quoteCount.toLocaleString()} tone="blue" />
                <KpiCard icon="fa-coins" label="Quote value" value={formatMoney(data.quoteTotal)} tone="indigo" />
                <KpiCard icon="fa-receipt" label="Invoices" value={invoiceCount.toLocaleString()} tone="green" />
                <KpiCard icon="fa-money-bill-wave" label="Invoice value" value={formatMoney(data.invoiceTotal)} tone="violet" />
            </div>

            <AiInsights insights={insights} loading={insightsLoading} source={insightSource} />

            <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <DetailTable
                    title="Quotations"
                    icon="fa-file-lines"
                    count={quoteCount}
                    total={data.quoteTotal}
                    rows={data.quotations}
                    emptyMessage={'No quotations in this period for ' + data.repName + '.'}
                    viewBase={data.links.orderViewBase}
                    idField="id"
                    numberField="order_number"
                    showStatus={false}
                    columns={[
                        { key: 'order_number', label: 'Quote #' },
                        { key: 'quote_date', label: 'Date', type: 'date' },
                        { key: 'customer_name', label: 'Customer' },
                        { key: 'amount', label: 'Amount', align: 'end' },
                    ]}
                />
                <DetailTable
                    title="Invoices"
                    icon="fa-receipt"
                    count={invoiceCount}
                    total={data.invoiceTotal}
                    rows={data.invoices}
                    emptyMessage={'No invoices in this period for ' + data.repName + '.'}
                    viewBase={data.links.invoiceViewBase}
                    idField="id"
                    numberField="invoice_number"
                    showStatus={true}
                    columns={[
                        { key: 'invoice_number', label: 'Invoice #' },
                        { key: 'invoice_date', label: 'Date', type: 'date' },
                        { key: 'customer_name', label: 'Customer' },
                        { key: 'amount', label: 'Amount', align: 'end' },
                        { key: 'status', label: 'Status' },
                    ]}
                />
            </div>
        </div>
    );
}

const root = document.getElementById('sa-rep-detail-root');
if (root && window.REP_DETAIL_DATA) {
    ReactDOM.createRoot(root).render(<RepDetailApp data={window.REP_DETAIL_DATA} />);
}
