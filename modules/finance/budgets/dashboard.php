<?php
require_once __DIR__ . '/lib.php';
requireFinanceOrAdmin();

$qs = function (array $extra = []) {
    return '?' . http_build_query(array_merge($_GET ?: [], $extra));
};

$budgetId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($budgetId <= 0) redirect('index.php?module=finance');

$st = $pdo->prepare('SELECT * FROM budgets WHERE id = ?');
$st->execute([$budgetId]);
$budget = $st->fetch(PDO::FETCH_ASSOC);
if (!$budget) redirect('index.php?module=finance');

$periodType = $_GET['period_type'] ?? ($budget['period_type'] ?? 'monthly');
if (!in_array($periodType, ['monthly', 'quarterly', 'yearly'], true)) $periodType = 'monthly';
$periodKey = $_GET['period'] ?? ($periodType === 'monthly' ? date('Y-m') : ($periodType === 'yearly' ? date('Y') : (date('Y') . '-Q' . (int)ceil(((int)date('n')) / 3))));
[$periodStart, $periodEnd] = budget_parse_period($periodType, (string)$periodKey);

// Items
$st = $pdo->prepare('SELECT * FROM budget_items WHERE budget_id = ? AND is_active = 1 ORDER BY id DESC');
$st->execute([$budgetId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalBudgeted = 0.0;
$totalActual = 0.0;
$byCat = [];
foreach ($items as $it) {
    $iid = (int) $it['id'];
    $budgeted = (float) ($it['budgeted_amount'] ?? 0);
    $actual = budget_compute_item_actual($iid, $periodStart, $periodEnd);
    $cat = trim((string)($it['category'] ?? ''));
    if ($cat === '') $cat = 'Uncategorized';
    $byCat[$cat] = ($byCat[$cat] ?? 0) + $actual;
    $totalBudgeted += $budgeted;
    $totalActual += $actual;
}

arsort($byCat);
$topCats = array_slice($byCat, 0, 10, true);

$spentPct = budget_compute_variance_percent($totalBudgeted, $totalActual);
if ($spentPct < 0) $spentPct = 0;

$page_title = 'Budget dashboard - ' . ($budget['name'] ?? '');
include __DIR__ . '/../includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
    .bud-shell { font-family: 'Outfit', system-ui, -apple-system, sans-serif; font-size: 16px; color: #374151; }
    .dash-card { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
    .bud-kpi-card { display: flex; align-items: flex-start; gap: 1rem; }
    .bud-kpi-icon {
        width: 48px; height: 48px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; color: #fff; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
    }
    .bud-kpi-icon--budget { background: linear-gradient(145deg, #059669 0%, #34d399 100%); }
    .bud-kpi-icon--spent { background: linear-gradient(145deg, #1d4ed8 0%, #60a5fa 100%); }
    .bud-kpi-icon--pct { background: linear-gradient(145deg, #6d28d9 0%, #a78bfa 100%); }
    .bud-kpi-body { min-width: 0; flex: 1; }
</style>

<main class="main-content bud-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <a href="budget.php<?= htmlspecialchars($qs(['id' => $budgetId, 'module' => 'finance', 'period_type' => $periodType, 'period' => $periodKey])) ?>" class="btn btn-outline-secondary btn-sm rounded-2">
                    <i class="bi bi-arrow-left me-1"></i> Budget
                </a>
                <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                    <i class="fas fa-gauge-high text-[#2563EB]"></i><span>Dashboard</span>
                </h1>
                <div class="flex-1 min-w-[8px]"></div>
                <span class="text-muted small">Period: <?= htmlspecialchars($periodStart) ?> ? <?= htmlspecialchars($periodEnd) ?></span>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <div class="row g-3 mb-3">
                <div class="col-12 col-lg-4">
                    <div class="dash-card p-4 bud-kpi-card">
                        <div class="bud-kpi-icon bud-kpi-icon--budget" aria-hidden="true"><i class="fas fa-coins"></i></div>
                        <div class="bud-kpi-body">
                            <div class="text-muted small fw-semibold">Total budgeted</div>
                            <div class="h3 m-0 text-gray-900"><?= htmlspecialchars($budget['currency'] ?? 'TZS') ?> <?= budget_money($totalBudgeted) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="dash-card p-4 bud-kpi-card">
                        <div class="bud-kpi-icon bud-kpi-icon--spent" aria-hidden="true"><i class="fas fa-receipt"></i></div>
                        <div class="bud-kpi-body">
                            <div class="text-muted small fw-semibold">Actual spent</div>
                            <div class="h3 m-0 text-gray-900"><?= htmlspecialchars($budget['currency'] ?? 'TZS') ?> <?= budget_money($totalActual) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="dash-card p-4 bud-kpi-card">
                        <div class="bud-kpi-icon bud-kpi-icon--pct" aria-hidden="true"><i class="fas fa-chart-pie"></i></div>
                        <div class="bud-kpi-body">
                            <div class="text-muted small fw-semibold">Spent %</div>
                            <div class="h3 m-0 text-gray-900"><?= number_format($spentPct, 1) ?>%</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-lg-5">
                    <div class="dash-card p-3">
                        <div class="fw-bold text-gray-800 px-2 pt-2 pb-1">
                            <i class="fas fa-tachometer-alt text-[#2563EB] me-2"></i>Overall budget health
                        </div>
                        <div id="gauge" style="min-height: 320px;"></div>
                    </div>
                </div>
                <div class="col-12 col-lg-7">
                    <div class="dash-card p-3">
                        <div class="fw-bold text-gray-800 px-2 pt-2 pb-1">
                            <i class="fas fa-chart-column text-[#2563EB] me-2"></i>Top-spending categories
                        </div>
                        <div id="bars" style="min-height: 320px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
const spentPct = <?= json_encode((float)$spentPct) ?>;
const gaugeColor = spentPct >= 100 ? '#991b1b' : (spentPct >= 90 ? '#dc2626' : (spentPct >= 75 ? '#f59e0b' : '#16a34a'));

new ApexCharts(document.querySelector("#gauge"), {
  chart: { type: 'radialBar', height: 320 },
  series: [Math.min(150, Math.max(0, spentPct))],
  labels: ['Spent %'],
  colors: [gaugeColor],
  plotOptions: {
    radialBar: {
      hollow: { size: '60%' },
      dataLabels: {
        name: { fontSize: '14px' },
        value: { fontSize: '26px', formatter: v => `${v.toFixed(1)}%` }
      }
    }
  }
}).render();

const cats = <?= json_encode(array_keys($topCats)) ?>;
const vals = <?= json_encode(array_values($topCats)) ?>;

new ApexCharts(document.querySelector("#bars"), {
  chart: { type: 'bar', height: 320, toolbar: { show: false } },
  series: [{ name: 'Actual spent', data: vals }],
  xaxis: { categories: cats, labels: { rotate: -35 } },
  yaxis: { labels: { formatter: v => v.toLocaleString(undefined, { maximumFractionDigits: 0 }) } },
  dataLabels: { enabled: false },
  plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
  colors: ['#2563EB']
}).render();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

