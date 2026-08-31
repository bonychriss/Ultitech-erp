<?php require_once '../../includes/functions.php';  global $pdo; $journals = $pdo->query("SELECT * FROM erp_journal_entries ORDER BY date DESC LIMIT 50")->fetchAll(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Journal Entries - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; } .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 100%; padding: 24px; } .page-wrapper { margin-left: 220px; min-height: 100vh; } @media (max-width: 768px) { .page-wrapper { margin-left: 0; } } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; } .table { width: 100%; border-collapse: collapse; } .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; } .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; } .table tr:hover { background: #f8f9fa; } .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; } .btn-primary { background: #1a73e8; color: white; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions"><a href="../index.php" class="btn btn-secondary">â† Back</a><a href="create-journal.php" class="btn btn-primary">+ New Journal Entry</a></div></div>
    <div class="container"><div class="card"><table class="table"><thead><tr><th>Entry #</th><th>Date</th><th>Description</th><th>Actions</th></tr></thead><tbody>
    <?php if (empty($journals)): ?><tr><td colspan="4" style="text-align: center; padding: 32px; color: #5f6368;">No journal entries yet.</td></tr><?php else: ?><?php foreach ($journals as $je): ?><tr><td><?= htmlspecialchars($je['entry_number']) ?></td><td><?= date('M d, Y', strtotime($je['date'])) ?></td><td><?= htmlspecialchars($je['description'] ?? '-') ?></td><td><a href="view-journal.php?id=<?= $je['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">View</a></td></tr><?php endforeach; ?><?php endif; ?>
    </tbody></table></div></div>
</div>
</body>
</html>

