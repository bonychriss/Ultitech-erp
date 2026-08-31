<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (!isset($_GET['id'])) redirect('index.php');
$id = $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM shippers WHERE id = ?");
$stmt->execute([$id]);
$shipper = $stmt->fetch();

if (!$shipper) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = clean_input($_POST['name']);
    $contact_person = clean_input($_POST['contact_person']);
    $email = clean_input($_POST['email']);
    $phone = clean_input($_POST['phone']);
    $website = clean_input($_POST['website']);
    $service_type = $_POST['service_type'];
    $currency = $_POST['currency'];
    $average_delivery_days = intval($_POST['average_delivery_days']);
    $reliability_score = floatval($_POST['reliability_score']);
    $cost_per_kg = floatval($_POST['cost_per_kg']);
    $cost_per_cbm = floatval($_POST['cost_per_cbm']);
    
    try {
        $stmt = $pdo->prepare("UPDATE shippers SET name=?, contact_person=?, email=?, phone=?, website=?, service_type=?, currency=?, average_delivery_days=?, reliability_score=?, cost_per_kg=?, cost_per_cbm=? WHERE id=?");
        if ($stmt->execute([$name, $contact_person, $email, $phone, $website, $service_type, $currency, $average_delivery_days, $reliability_score, $cost_per_kg, $cost_per_cbm, $id])) {
            flash('success', 'Logistics Partner updated: ' . $name);
            redirect('index.php');
        }
    } catch (Exception $e) {
        $error = "Update Error: " . $e->getMessage();
    }
}

$page_title = 'Edit Logistics Partner';
include '../../includes/header.php';
?>

<!-- Tailwind CSS CDN -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<!-- Google Fonts: Inter -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; background: #f8fafc !important; }
    .main-content { background: #f8fafc !important; }
    .glass-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-radius: 24px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05); }
    .input-premium { width: 100%; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; transition: all 0.3s ease; }
    .input-premium:focus { border-color: #3b82f6; ring: 4px; ring-color: rgba(59, 130, 246, 0.1); outline: none; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
    
    /* Interaction Glow */
    .input-premium.is-dirty {
        border-color: #10b981 !important;
        box-shadow: 0 0 10px rgba(16, 185, 129, 0.2) !important;
    }
    
    .label-premium { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; margin-bottom: 6px; display: block; }
    .btn-action { padding: 14px 28px; font-size: 14px; font-weight: 700; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }

    .icon-pricing-colorful {
        background: linear-gradient(135deg, #fcd34d 0%, #f59e0b 100%) !important;
        color: white !important;
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2) !important;
        border: none !important;
    }
</style>

<main class="main-content">
    <div class="max-w-[1200px] mx-auto px-8 py-10">
        
        <div class="flex items-center justify-between mb-10">
            <div class="flex items-center gap-5">
                <a href="index.php" class="w-12 h-12 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-400 hover:text-slate-900 transition-all hover:border-slate-400 shadow-sm">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Edit Partner Profile</h1>
                    <p class="text-xs text-slate-500 mt-1 tracking-widest opacity-60">Modify existing logistics & freight relationship: <?= htmlspecialchars($shipper['name']) ?></p>
                </div>
            </div>
        </div>

        <?php if($error): ?>
        <div class="mb-8 p-4 bg-red-50 border border-red-100 rounded-2xl text-red-600 text-sm font-medium flex items-center gap-3">
            <i class="bi bi-exclamation-octagon"></i> <?= $error ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-8">
            <div class="glass-card p-10">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-12">
                    
                    <!-- Section 1: Company Identity -->
                    <div class="space-y-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center"><i class="bi bi-building-check"></i></div>
                            <h3 class="font-bold text-slate-800 uppercase text-xs tracking-widest">Company Identity</h3>
                        </div>
                        
                        <div>
                            <label class="label-premium">Company Name *</label>
                            <input type="text" name="name" class="input-premium" placeholder="e.g. Global Freight Solutions" required value="<?= htmlspecialchars($shipper['name']) ?>">
                        </div>

                        <div>
                            <label class="label-premium">Official Website</label>
                            <input type="text" name="website" class="input-premium" placeholder="e.g. www.company.com" value="<?= htmlspecialchars($shipper['website']) ?>">
                        </div>

                        <div>
                            <label class="label-premium">Contact Person</label>
                            <input type="text" name="contact_person" class="input-premium" placeholder="Primary liaison name" value="<?= htmlspecialchars($shipper['contact_person']) ?>">
                        </div>

                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="label-premium">Business Email</label>
                                <input type="email" name="email" class="input-premium" placeholder="logistics@company.com" value="<?= htmlspecialchars($shipper['email']) ?>">
                            </div>
                            <div>
                                <label class="label-premium">Phone / Mobile</label>
                                <input type="text" name="phone" class="input-premium" placeholder="Int. format" value="<?= htmlspecialchars($shipper['phone']) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Logistics Profile -->
                    <div class="space-y-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-purple-50 text-purple-500 flex items-center justify-center"><i class="bi bi-geo-alt"></i></div>
                            <h3 class="font-bold text-slate-800 uppercase text-xs tracking-widest">Logistics Profile</h3>
                        </div>

                        <div>
                            <label class="label-premium">Primary Service Type</label>
                            <select name="service_type" class="input-premium">
                                <option value="sea" <?= ($shipper['service_type'] == 'sea') ? 'selected' : '' ?>>Sea Freight (Ocean)</option>
                                <option value="air" <?= ($shipper['service_type'] == 'air') ? 'selected' : '' ?>>Air Freight (Express)</option>
                                <option value="road" <?= ($shipper['service_type'] == 'road') ? 'selected' : '' ?>>Road / Ground</option>
                                <option value="courier" <?= ($shipper['service_type'] == 'courier') ? 'selected' : '' ?>>Courier (DHL/FedEx)</option>
                                <option value="freight" <?= ($shipper['service_type'] == 'freight') ? 'selected' : '' ?>>General Freight</option>
                            </select>
                        </div>

                        <div>
                            <label class="label-premium">Average Transit Days</label>
                            <input type="number" name="average_delivery_days" class="input-premium" value="<?= $shipper['average_delivery_days'] ?>">
                        </div>

                        <div>
                            <label class="label-premium">Reliability Score (1.0 - 5.0)</label>
                            <input type="number" step="0.1" max="5" name="reliability_score" class="input-premium" value="<?= $shipper['reliability_score'] ?>">
                        </div>
                    </div>

                    <!-- Section 3: Pricing Metrics -->
                    <div class="space-y-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-lg icon-pricing-colorful flex items-center justify-center shadow-md"><i class="bi bi-coin"></i></div>
                            <h3 class="font-bold text-slate-800 uppercase text-xs tracking-widest">Pricing Metrics</h3>
                        </div>

                        <div>
                            <label class="label-premium">Default Currency</label>
                            <select name="currency" class="input-premium">
                                <option value="USD" <?= ($shipper['currency'] == 'USD') ? 'selected' : '' ?>>USD - US Dollar</option>
                                <option value="CNY" <?= ($shipper['currency'] == 'CNY') ? 'selected' : '' ?>>CNY - Chinese Yuan</option>
                                <option value="TZS" <?= ($shipper['currency'] == 'TZS') ? 'selected' : '' ?>>TZS - Tanzanian Shilling</option>
                                <option value="GBP" <?= ($shipper['currency'] == 'GBP') ? 'selected' : '' ?>>GBP - British Pound</option>
                                <option value="KES" <?= ($shipper['currency'] == 'KES') ? 'selected' : '' ?>>KES - Kenyan Shilling</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="label-premium">Rate / KG</label>
                                <input type="number" step="0.01" name="cost_per_kg" class="input-premium" value="<?= $shipper['cost_per_kg'] ?>">
                            </div>
                            <div>
                                <label class="label-premium">Rate / CBM</label>
                                <input type="number" step="0.01" name="cost_per_cbm" class="input-premium" value="<?= $shipper['cost_per_cbm'] ?>">
                            </div>
                        </div>

                        <div class="p-6 bg-slate-50 rounded-none border border-slate-100 mt-6">
                            <p class="text-[10px] text-blue-500 font-extrabold uppercase tracking-widest mb-2">Pro Tip</p>
                            <p class="text-xs text-blue-600 leading-relaxed font-bold">Updating these rates will automatically assist in future shipment cost estimations.</p>
                        </div>
                    </div>

                </div>

                <div class="flex items-center justify-end gap-4 pt-12 mt-8 border-t border-slate-50">
                    <a href="index.php" class="btn-action px-10 bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all" style="border-radius: 9999px !important;">Cancel</a>
                    <button type="submit" class="btn-action px-10 bg-blue-600 text-white shadow-xl shadow-blue-100 hover:bg-blue-700 transition-all" style="border-radius: 9999px !important;">
                        Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>
</main>

<script>
document.querySelectorAll('.input-premium').forEach(el => {
    const markDirty = () => {
        el.classList.add('is-dirty');
    };
    el.addEventListener('input', markDirty);
    el.addEventListener('change', markDirty);
});
</script>

<?php include '../../includes/footer.php'; ?>
