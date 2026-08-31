<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../classes/StockStatistics.php';

requireLogin();
// if (!hasRole(['admin', 'procurement'])) {
//     redirect('../../dashboard.php');
// }

$stats = new StockStatistics($pdo);
$quickStats = $stats->getQuickStats();
$trendData = $stats->getMonthlyPurchaseTrend();
$catDist = $stats->getStockDistributionByCategory();
$stockStatus = $stats->getStockStatusDistribution();
$topProductsLimit = 5;
$topProducts = $stats->getTopProductsByValue($topProductsLimit);

$page_title = 'Analytics & Statistics';
include '../../includes/header.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Analytics Dashboard</h4>
             <a href="logistics.php" class="btn btn-outline-primary btn-sm rounded-0"><i class="fas fa-ship"></i> Logistics Analytics</a>
        </div>
            
            <!-- Quick Stats -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card bg-info text-white h-100 border-0 shadow-sm" style="border-radius: 0;">
                        <div class="card-body py-3">
                            <h6 class="text-uppercase small mb-2 opacity-75">Today's Purchases</h6>
                            <div class="d-flex flex-column">
                                <?php $totalPurchases = $quickStats['purchases_usd'] + $quickStats['purchases_tzs']; ?>
                                <span class="fw-bold fs-4">TSh <?php echo number_format($totalPurchases, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                     <div class="card bg-warning text-dark h-100 border-0 shadow-sm" style="border-radius: 0;">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-uppercase small mb-1 opacity-75">Low Stock Items</h6>
                                    <h4 class="mb-0 fw-bold display-6"><?php echo $quickStats['low_stock']; ?></h4>
                                </div>
                                <i class="fas fa-exclamation-triangle fa-2x opacity-25"></i>
                            </div>
                        </div>
                    </div>
                </div>
                 <div class="col-md-4">
                    <div class="card bg-success text-white h-100 border-0 shadow-sm" style="border-radius: 0;">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-uppercase small mb-1 opacity-75">Stock Accuracy</h6>
                                    <h4 class="mb-0 fw-bold display-6">100%</h4>
                                </div>
                                <i class="fas fa-check-circle fa-2x opacity-25"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <div class="card h-100 border-0 shadow-sm" style="border-radius: 0;">
                        <div class="card-header bg-dark text-white py-2 px-3 fw-bold small" style="border-radius: 0;">Purchase Trends (Last 6 Months)</div>
                        <div class="card-body">
                            <div style="height: 250px;">
                                <canvas id="trendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm" style="border-radius: 0;">
                         <div class="card-header bg-dark text-white py-2 px-3 fw-bold small" style="border-radius: 0;">Stock Status</div>
                        <div class="card-body">
                            <div style="height: 250px;">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
             <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm" style="border-radius: 0;">
                        <div class="card-header bg-dark text-white py-2 px-3 fw-bold small" style="border-radius: 0;">Category Distribution</div>
                        <div class="card-body">
                             <div style="height: 250px;">
                                <canvas id="categoryChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm" style="border-radius: 0;">
                        <div class="card-header bg-dark text-white py-2 px-3 fw-bold small" style="border-radius: 0;">Top <?php echo $topProductsLimit; ?> Products by Value</div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-hover mb-0" style="font-size: 0.85rem;">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3">Product</th>
                                            <th class="text-end">Qty</th>
                                            <th class="text-end pe-3">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($topProducts as $p): ?>
                                        <tr>
                                            <td class="ps-3">
                                                <div class="fw-bold"><?php echo htmlspecialchars($p['name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($p['product_code']); ?></small>
                                            </td>
                                            <td class="text-end align-middle"><?php echo $p['quantity']; ?></td>
                                            <td class="text-end align-middle pe-3 fw-bold text-primary">
                                                <?php 
                                                    // Force TZS display as per user request
                                                    $sym = 'TSh ';
                                                    echo $sym . number_format($p['total_value'], 2); 
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

    </div>
</main>

<script>
// Trend Chart
const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($trendData['labels']); ?>,
        datasets: [
            {
                label: 'USD Purchases',
                data: <?php echo json_encode($trendData['usd']); ?>,
                borderColor: '#0d6efd',
                tension: 0.1,
                borderWidth: 2,
                pointRadius: 3
            },
            {
                label: 'TZS Purchases',
                data: <?php echo json_encode($trendData['tzs']); ?>,
                borderColor: '#198754', // Green for TZS
                tension: 0.1,
                borderWidth: 2,
                pointRadius: 3
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    usePointStyle: true,
                    boxWidth: 6
                }
            }
        },
        interaction: {
            mode: 'index',
            intersect: false,
        },
        scales: {
            x: { grid: { display: false } },
            y: { grid: { borderDash: [2, 4] } }
        }
    }
});

// Status Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['In Stock', 'Low Stock', 'Out of Stock'],
        datasets: [{
            data: [
                <?php echo $stockStatus['in_stock']; ?>,
                <?php echo $stockStatus['low_stock']; ?>,
                <?php echo $stockStatus['out_of_stock']; ?>
            ],
            backgroundColor: ['#198754', '#ffc107', '#dc3545'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8
                }
            }
        }
    }
});

// Category Chart
const catCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(catCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($catDist, 'name')); ?>,
        datasets: [{
            label: 'Product Count',
            data: <?php echo json_encode(array_column($catDist, 'count')); ?>,
            backgroundColor: '#0dcaf0',
            borderRadius: 0
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: { grid: { borderDash: [2, 4] } },
            y: { grid: { display: false } }
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
