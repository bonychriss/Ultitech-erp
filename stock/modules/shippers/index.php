<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

// Simple Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM shippers WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'Shipper deleted successfully!');
    } catch (PDOException $e) {
        flash('success', 'Cannot delete shipper in use.', 'danger');
    }
    redirect('index.php');
}

$stmt = $pdo->query("SELECT * FROM shippers ORDER BY name ASC");
$shippers = $stmt->fetchAll();

$page_title = 'Freight Forwarders & Shippers';
include '../../includes/header.php';
?>

<!-- Tailwind CSS CDN -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<!-- Google Fonts: Inter -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; background: #f8fafc !important; }
    .main-content { background: #f8fafc !important; }
    .premium-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .premium-table th { padding: 16px 24px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; border-bottom: 1px solid #f1f5f9; background: #fafafa; }
    .premium-table td { padding: 20px 24px; font-size: 13px; color: #1e293b; border-bottom: 1px solid #f8fafc; vertical-align: middle; background: #fff; }
    .card-premium { background: #fff; border-radius: 20px; border: 1px solid #f1f5f9; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05); overflow: hidden; }
    
    .status-pill { padding: 4px 12px; border-radius: 100px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
    .score-high { background: #f0fdf4; color: #22c55e; border: 1px solid #dcfce7; }
    .score-med { background: #fffbeb; color: #d97706; border: 1px solid #fef3c7; }
    .score-low { background: #fff1f2; color: #e11d48; border: 1px solid #ffe4e6; }
    
    .action-btn { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 10px; transition: all 0.2s; }
</style>

<main class="main-content">
    <div class="max-w-[1600px] mx-auto px-8 py-10">
        
        <!-- Header Section -->
        <div class="flex items-center justify-between mb-10">
            <div class="flex items-center gap-6">
                <a href="../shipments/index.php" class="w-12 h-12 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-400 hover:text-slate-900 transition-all hover:border-slate-400 shadow-sm">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Logistics Partners</h1>
                    <p class="text-xs text-slate-500 mt-2 font-normal tracking-widest opacity-70">Freight Forwarders, Shippers & Carriers</p>
                </div>
            </div>
            <a href="add.php" class="px-6 py-2.5 bg-blue-600 text-white rounded-full text-xs font-bold hover:bg-blue-700 transition-all shadow-lg hover:shadow-blue-100 transform hover:-translate-y-0.5 active:scale-95" style="border-radius: 9999px !important;">
                <i class="fas fa-plus text-[10px] mr-2"></i> Register Carrier
            </a>
        </div>

        <?php flash('success'); ?>

        <div class="card-premium">
            <div class="overflow-x-auto">
                <table class="premium-table">
                    <thead>
                        <tr>
                            <th style="width: 250px">Carrier Name</th>
                            <th>Service Profile</th>
                            <th>Contact Person</th>
                            <th class="text-center">Reliability</th>
                            <th class="text-center">Transit Avg</th>
                            <th class="text-right">Rate / KG</th>
                            <th class="text-right">Rate / CBM</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($shippers as $ship): 
                            $score = (float)$ship['reliability_score'];
                            $score_class = ($score >= 4.5) ? 'score-high' : (($score >= 3.5) ? 'score-med' : 'score-low');
                        ?>
                        <tr class="group transition-colors hover:bg-slate-50/50">
                            <td>
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-400 group-hover:bg-blue-600 group-hover:text-white transition-all">
                                        <i class="bi bi-truck text-lg"></i>
                                    </div>
                                    <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($ship['name']) ?></div>
                                </div>
                            </td>
                            <td>
                                <?php
                                $sType = strtolower($ship['service_type'] ?: 'standard');
                                $sColor = match($sType) {
                                    'sea' => 'text-blue-600',
                                    'air' => 'text-purple-600',
                                    'road' => 'text-emerald-600',
                                    'courier' => 'text-amber-600',
                                    'freight' => 'text-indigo-600',
                                    default => 'text-slate-500'
                                };
                                ?>
                                <span class="<?= $sColor ?> text-[10px] font-bold uppercase tracking-widest">
                                    <?= htmlspecialchars($ship['service_type'] ?: 'Standard') ?>
                                </span>
                            </td>
                            <td>
                                <div class="font-bold text-slate-700 text-xs"><?= htmlspecialchars($ship['contact_person'] ?: 'General Office') ?></div>
                                <div class="text-[10px] text-slate-400 mt-1 flex items-center gap-1">
                                    <i class="bi bi-telephone-outbound text-[8px]"></i> <?= htmlspecialchars($ship['phone'] ?: 'N/A') ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="status-pill <?= $score_class ?>">
                                    <i class="bi bi-star-fill text-[8px] mr-1"></i> <?= number_format($score, 1) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="font-bold text-slate-700 text-xs"><?= $ship['average_delivery_days'] ?> Days</div>
                                <div class="text-[9px] text-slate-400 uppercase font-bold tracking-tighter">Mean Duration</div>
                            </td>
                            <td class="text-right">
                                <div class="font-extrabold text-slate-900 text-sm"><?= $ship['currency'] ?> <?= number_format($ship['cost_per_kg'], 2) ?></div>
                            </td>
                            <td class="text-right">
                                <div class="font-extrabold text-slate-900 text-sm"><?= $ship['currency'] ?> <?= number_format($ship['cost_per_cbm'], 2) ?></div>
                            </td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="edit.php?id=<?= $ship['id'] ?>" class="action-btn bg-slate-50 text-slate-400 hover:bg-slate-900 hover:text-white" title="Modify Partner">
                                        <i class="bi bi-pencil-square text-xs"></i>
                                    </a>
                                    <button onclick="confirmDelete(<?= $ship['id'] ?>)" class="action-btn bg-red-50 text-red-500 hover:bg-red-500 hover:text-white" title="Remove Carrier">
                                        <i class="bi bi-trash3 text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($shippers)): ?>
                        <tr>
                            <td colspan="8" class="py-32 text-center">
                                <div class="mb-4 flex justify-center">
                                    <div class="w-20 h-20 rounded-full bg-slate-50 flex items-center justify-center text-slate-200">
                                        <i class="bi bi-person-rolodex text-5xl"></i>
                                    </div>
                                </div>
                                <p class="text-sm text-slate-400 font-bold uppercase tracking-widest">No logistics partners registered</p>
                                <a href="add.php" class="mt-4 inline-block text-blue-600 text-xs font-bold hover:underline">Register your first carrier</a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function confirmDelete(id) {
    Swal.fire({
        title: 'Remove Logistics Partner?',
        text: "This will remove the carrier from your registry. Shipments already linked to this carrier will maintain their history, but you won't be able to select them for new orders.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#0f172a',
        confirmButtonText: 'Yes, remove carrier',
        customClass: {
            popup: 'rounded-3xl',
            confirmButton: 'rounded-full px-8 py-2.5',
            cancelButton: 'rounded-full px-8 py-2.5'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'index.php?delete=' + id;
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
