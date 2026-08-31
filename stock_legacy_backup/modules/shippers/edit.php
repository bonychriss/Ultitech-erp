<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (!isset($_GET['id'])) redirect('index.php');
$id = $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM shippers WHERE id = ?");
$stmt->execute([$id]);
$shipper = $stmt->fetch();

if (!$shipper) redirect('index.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = clean_input($_POST['name']);
    $contact_person = clean_input($_POST['contact_person']);
    $email = clean_input($_POST['email']);
    $phone = clean_input($_POST['phone']);
    $website = clean_input($_POST['website']);
    $service_type = $_POST['service_type'];
    $average_delivery_days = $_POST['average_delivery_days'];
    $reliability_score = $_POST['reliability_score'];
    $cost_per_kg = $_POST['cost_per_kg'];
    $cost_per_cbm = $_POST['cost_per_cbm'];
    
    $stmt = $pdo->prepare("UPDATE shippers SET name=?, contact_person=?, email=?, phone=?, website=?, service_type=?, average_delivery_days=?, reliability_score=?, cost_per_kg=?, cost_per_cbm=? WHERE id=?");
    
    if ($stmt->execute([$name, $contact_person, $email, $phone, $website, $service_type, $average_delivery_days, $reliability_score, $cost_per_kg, $cost_per_cbm, $id])) {
        flash('success', 'Shipper updated successfully');
        redirect('index.php');
    } else {
        $error = "Failed to update shipper";
    }
}

$page_title = 'Edit Shipper';
include '../../includes/header.php';
?>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Edit Shipper</h4>
            <a href="index.php" class="btn btn-secondary btn-sm rounded-0"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
        
        <div class="card border-0 rounded-0 shadow-sm">
            <div class="card-body">
                <form method="POST">
                    <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">Company Details</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Company Name *</label>
                            <input type="text" name="name" class="form-control rounded-0" required value="<?php echo htmlspecialchars($shipper['name']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Website</label>
                            <input type="url" name="website" class="form-control rounded-0" value="<?php echo htmlspecialchars($shipper['website']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control rounded-0" value="<?php echo htmlspecialchars($shipper['contact_person']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control rounded-0" value="<?php echo htmlspecialchars($shipper['email']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control rounded-0" value="<?php echo htmlspecialchars($shipper['phone']); ?>">
                        </div>
                    </div>
                    
                    <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">Service & Rates</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Primary Service Type</label>
                            <select name="service_type" class="form-select rounded-0">
                                <option value="sea" <?php echo ($shipper['service_type'] == 'sea') ? 'selected' : ''; ?>>Sea Freight</option>
                                <option value="air" <?php echo ($shipper['service_type'] == 'air') ? 'selected' : ''; ?>>Air Freight</option>
                                <option value="road" <?php echo ($shipper['service_type'] == 'road') ? 'selected' : ''; ?>>Road</option>
                                <option value="courier" <?php echo ($shipper['service_type'] == 'courier') ? 'selected' : ''; ?>>Courier (DHL/FedEx)</option>
                                <option value="freight" <?php echo ($shipper['service_type'] == 'freight') ? 'selected' : ''; ?>>General Freight</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Avg Days</label>
                            <input type="number" name="average_delivery_days" class="form-control rounded-0" value="<?php echo $shipper['average_delivery_days']; ?>">
                        </div>
                         <div class="col-md-2">
                            <label class="form-label">Reliability (1-5)</label>
                            <input type="number" step="0.1" max="5" name="reliability_score" class="form-control rounded-0" value="<?php echo $shipper['reliability_score']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Rate / KG ($)</label>
                            <input type="number" step="0.01" name="cost_per_kg" class="form-control rounded-0" value="<?php echo $shipper['cost_per_kg']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Rate / CBM ($)</label>
                            <input type="number" step="0.01" name="cost_per_cbm" class="form-control rounded-0" value="<?php echo $shipper['cost_per_cbm']; ?>">
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary rounded-0 px-4"><i class="fas fa-save"></i> Update Shipper</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
