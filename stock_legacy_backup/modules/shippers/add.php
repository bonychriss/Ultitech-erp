<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

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
    
    $stmt = $pdo->prepare("INSERT INTO shippers (name, contact_person, email, phone, website, service_type, average_delivery_days, reliability_score, cost_per_kg, cost_per_cbm) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$name, $contact_person, $email, $phone, $website, $service_type, $average_delivery_days, $reliability_score, $cost_per_kg, $cost_per_cbm])) {
        flash('success', 'Shipper added successfully');
        redirect('index.php');
    } else {
        $error = "Failed to add shipper";
    }
}

$page_title = 'Add New Shipper';
include '../../includes/header.php';
?>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Add New Shipper</h4>
            <a href="index.php" class="btn btn-secondary btn-sm rounded-0"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
        
        <div class="card border-0 rounded-0 shadow-sm">
            <div class="card-body">
                <form method="POST">
                    <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">Company Details</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Company Name *</label>
                            <input type="text" name="name" class="form-control rounded-0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Website</label>
                            <input type="url" name="website" class="form-control rounded-0" placeholder="https://...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control rounded-0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control rounded-0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control rounded-0">
                        </div>
                    </div>
                    
                    <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">Service & Rates</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Primary Service Type</label>
                            <select name="service_type" class="form-select rounded-0">
                                <option value="sea">Sea Freight</option>
                                <option value="air">Air Freight</option>
                                <option value="road">Road</option>
                                <option value="courier">Courier (DHL/FedEx)</option>
                                <option value="freight">General Freight</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Avg Days</label>
                            <input type="number" name="average_delivery_days" class="form-control rounded-0" value="30">
                        </div>
                         <div class="col-md-2">
                            <label class="form-label">Reliability (1-5)</label>
                            <input type="number" step="0.1" max="5" name="reliability_score" class="form-control rounded-0" value="5.0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Rate / KG ($)</label>
                            <input type="number" step="0.01" name="cost_per_kg" class="form-control rounded-0" value="0.00">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Rate / CBM ($)</label>
                            <input type="number" step="0.01" name="cost_per_cbm" class="form-control rounded-0" value="0.00">
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary rounded-0 px-4"><i class="fas fa-save"></i> Save Shipper</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
