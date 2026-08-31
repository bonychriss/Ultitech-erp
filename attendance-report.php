<?php
require_once '../includes/functions.php';
requireAdmin();

// Filters
$filterUser = isset($_GET['user']) ? trim($_GET['user']) : '';
$filterMinDist = isset($_GET['min_distance']) ? (int)$_GET['min_distance'] : 0;
// Date filter (default today)
$reportDate = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) { $reportDate = date('Y-m-d'); }
// Presets (today/yesterday shortcuts)
if (isset($_GET['preset'])) {
  $p = strtolower(trim($_GET['preset']));
  if ($p === 'today') { $reportDate = date('Y-m-d'); }
  if ($p === 'yesterday') { $reportDate = date('Y-m-d', strtotime('-1 day')); }
}

// Build query dynamically
$sql = "SELECT a.id, a.user_id, a.sign_type, a.signed_at, a.latitude, a.longitude, a.distance_from_office, u.full_name, u.department
  FROM attendance a
  LEFT JOIN users u ON a.user_id = u.id
  WHERE DATE(a.signed_at) = ?";
$params = [$reportDate];
if ($filterUser !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.username LIKE ?)";
    $params[] = '%' . $filterUser . '%';
    $params[] = '%' . $filterUser . '%';
}
if ($filterMinDist > 0) {
    $sql .= " AND a.distance_from_office >= ?";
    $params[] = $filterMinDist;
}
$sql .= " ORDER BY a.signed_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Attendance Report - <?= htmlspecialchars($reportDate) ?></title>
  <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>" />
  <style>
    .filter-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
    .filter-bar input { padding:8px 10px; font-size:14px; border:1px solid #d1d5db; border-radius:0; }
    .filter-bar button { padding:8px 14px; font-size:14px; }
    table.attendance { width:100%; border-collapse:collapse; background:#fff; box-shadow:0 2px 6px rgba(0,0,0,.06); }
    table.attendance th, table.attendance td { padding:10px 12px; font-size:13px; border-bottom:1px solid #eee; text-align:left; }
    table.attendance th { background:#1f2937; color:#fff; }
    table.attendance tr:hover { background:#f9fafb; }
    .badge-in { color:#059669; }
    .badge-out { color:#dc2626; }
    @media (max-width:640px){
        table.attendance th, table.attendance td { padding:6px 8px; font-size:11px; }
    }
  </style>
</head>
<body class="dashboard">
<?php require_once __DIR__ . '/../includes/header_admin.php'; ?>
<main class="main-content">
  <h2 style="margin-top:0;">Attendance (<?= htmlspecialchars($reportDate) ?>)</h2>
  <form class="filter-bar" method="get" action="attendance-report.php">
    <input type="date" name="date" value="<?= htmlspecialchars($reportDate) ?>" />
    <input type="text" name="user" placeholder="Filter by user" value="<?= htmlspecialchars($filterUser) ?>" />
    <input type="number" name="min_distance" placeholder="Min distance (m)" value="<?= $filterMinDist ?>" min="0" />
    <button type="submit" class="btn btn-secondary">Apply Filters</button>
    <a href="attendance-report.php?preset=today" class="btn" style="background:#111; color:#fff;">Today</a>
    <a href="attendance-report.php?preset=yesterday" class="btn" style="background:#4b5563; color:#fff;">Yesterday</a>
    <a href="attendance-report.php" class="btn" style="background:#000; color:#fff;">Reset</a>
  </form>
  <?php if (empty($rows)): ?>
    <p>No attendance records found for today.</p>
  <?php else: ?>
    <div class="table-responsive">
    <table class="attendance recent-admin">
      <thead>
        <tr>
          <th>ID</th>
          <th>User</th>
          <th>Department</th>
          <th>Type</th>
          <th>Signed At</th>
          <th>Distance (m)</th>
          <th>Lat</th>
          <th>Lon</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['full_name'] ?: ('User#'.$r['user_id'])) ?></td>
            <td><?= htmlspecialchars($r['department'] ?: 'â€”') ?></td>
            <td><?= $r['sign_type']==='sign_in' ? '<span class="badge-in">IN</span>' : '<span class="badge-out">OUT</span>' ?></td>
            <td><?= date('d/m/Y H:i', strtotime($r['signed_at'])) ?></td>
            <td><?= (int)$r['distance_from_office'] ?></td>
            <td><?= htmlspecialchars(number_format((float)$r['latitude'], 5)) ?></td>
            <td><?= htmlspecialchars(number_format((float)$r['longitude'], 5)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <div style="margin-top:20px; font-size:12px; color:#6b7280;">
    Radius: <?= defined('OFFICE_RADIUS_M') ? (int)OFFICE_RADIUS_M : 0 ?>m at (<?= defined('OFFICE_LAT')?OFFICE_LAT:'N/A' ?>, <?= defined('OFFICE_LON')?OFFICE_LON:'N/A' ?>)
  </div>
</main>
</body>
</html>
