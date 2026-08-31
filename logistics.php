<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

// --- LOGISTICS ANALYTICS ENGINE ---

// 1. Quick Stats
$stats = [
    'active_shipments' => 0,
    'total_cbm' => 0,
    'avg_days' => 0,
    'ecc_pending' => 0
];

// Active Shipments
$stmt = $pdo->query("SELECT COUNT(*) FROM shipments WHERE status NOT IN ('delivered', 'cancelled')");
$stats['active_shipments'] = $stmt->fetchColumn();

// Total CBM (This Year)
$stmt = $pdo->query("SELECT SUM(cbm) FROM shipments WHERE YEAR(created_at) = YEAR(CURRENT_DATE)");
$stats['total_cbm'] = number_format($stmt->fetchColumn() ?: 0, 2);

// ECC Pending
$stmt = $pdo->query("SELECT COUNT(*) FROM shipments WHERE (estimated_clearance_cost IS NULL OR estimated_clearance_cost = 0) AND status != 'cancelled'");
$stats['ecc_pending'] = $stmt->fetchColumn();

// 2. Shipper Performance (Cost & Time)
$stmt = $pdo->query("
    SELECT 
        sh.name, 
        COUNT(s.id) as shipment_count,
        AVG(s.cbm) as avg_cbm,
        AVG(CASE WHEN s.shipping_cost > 0 THEN s.shipping_cost / NULLIF(s.cbm, 0) ELSE 0 END) as avg_cost_per_cbm,
        AVG(DATEDIFF(s.eta, s.shipment_date)) as avg_transit_days
    FROM shipments s
    JOIN shippers sh ON s.shipper_id = sh.id
    WHERE s.status = 'delivered'
    GROUP BY sh.id
    ORDER BY shipment_count DESC
    LIMIT 5
");
$shipperPerf = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Cost Analysis Charts Data
$shipperLabels = [];
$costPerCbmData = [];
$transitTimeData = [];

foreach($shipperPerf as $row) {
    $shipperLabels[] = $row['name'];
    $costPerCbmData[] = number_format($row['avg_cost_per_cbm'], 2);
    $transitTimeData[] = number_format($row['avg_transit_days'], 1);
}

$page_title = 'Logistics Analytics';
include '../../includes/header.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Logistics & Shipping Analytics</h4>
            <div class="btn-group">
                <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-0"><i class="fas fa-arrow-left"></i> Main Dashboard</a>
                <a href="../shipments/index.php" class="btn btn-primary btn-sm rounded-0"><i class="fas fa-truck"></i> Shipments</a>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white h-100 border-0 shadow-sm rounded-0">
                    <div class="card-body py-3">
                        <h6 class="text-uppercase small mb-2 opacity-75">Active Shipments</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['active_shipments']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-dark h-100 border-0 shadow-sm rounded-0">
                    <div class="card-body py-3">
                        <h6 class="text-uppercase small mb-2 opacity-75">Volume YTD (CBM)</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['total_cbm']; ?> m³</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark h-100 border-0 shadow-sm rounded-0">
                    <div class="card-body py-3">
                        <h6 class="text-uppercase small mb-2 opacity-75">Missing ECC</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['ecc_pending']; ?></h2>
                    </div>
                </div>
            </div>
             <div class="col-md-3">
                <div class="card bg-success text-white h-100 border-0 shadow-sm rounded-0">
                    <div class="card-body py-3">
                        <h6 class="text-uppercase small mb-2 opacity-75">Avg Transit Time</h6>
                        <h2 class="fw-bold mb-0"><?php echo !empty($transitTimeData) ? round(array_sum($transitTimeData)/count($transitTimeData)) : 0; ?> days</h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row g-4 mb-4">
            <!-- Cost Efficiency -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm rounded-0">
                    <div class="card-header bg-dark text-white py-2 fw-bold small rounded-0">Cost Efficiency (Avg $/CBM by Shipper)</div>
                    <div class="card-body">
                         <div style="height: 300px;">
                            <canvas id="costChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Transit Performance -->
             <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm rounded-0">
                    <div class="card-header bg-dark text-white py-2 fw-bold small rounded-0">Transit Performance (Avg Days)</div>
                    <div class="card-body">
                         <div style="height: 300px;">
                            <canvas id="timeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Table -->
        <div class="card border-0 shadow-sm rounded-0">
            <div class="card-header bg-white fw-bold py-3 border-bottom rounded-0">Shipper Performance Detailed</div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">Shipper</th>
                            <th class="text-center">Total Shipments</th>
                            <th class="text-center">Avg Volume (CBM)</th>
                            <th class="text-end">Avg Cost / CBM</th>
                            <th class="text-center pe-3">Reliability Index</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($shipperPerf as $row): ?>
                        <tr>
                            <td class="ps-3 fw-bold"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td class="text-center"><?php echo $row['shipment_count']; ?></td>
                            <td class="text-center"><?php echo number_format($row['avg_cbm'], 3); ?></td>
                            <td class="text-end text-success fw-bold">$<?php echo number_format($row['avg_cost_per_cbm'], 2); ?></td>
                            <td class="text-center pe-3">
                                <?php 
                                    // Mock reliability calculation based on data
                                    $rel = 5.0 - ($row['avg_transit_days'] > 45 ? 1.0 : 0);
                                    echo number_format($rel, 1);
                                ?>
                                <span class="text-warning small"><i class="fas fa-star"></i></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                         <?php if(empty($shipperPerf)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No sufficient data for analysis. Delivered shipments required.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<script>
// Cost Chart
new Chart(document.getElementById('costChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($shipperLabels); ?>,
        datasets: [{
            label: 'Avg Cost per CBM ($)',
            data: <?php echo json_encode($costPerCbmData); ?>,
            backgroundColor: '#0d6efd',
            borderRadius: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Time Chart
new Chart(document.getElementById('timeChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($shipperLabels); ?>,
        datasets: [{
            label: 'Avg Transit Days',
            data: <?php echo json_encode($transitTimeData); ?>,
            backgroundColor: '#198754',
             borderRadius: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: { legend: { display: false } }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
