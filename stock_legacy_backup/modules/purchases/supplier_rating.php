<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';

// Validate Token
$token = $_GET['token'] ?? '';
if (empty($token)) die("Invalid Access");

$stmt = $pdo->prepare("SELECT p.id, p.purchase_no, s.name as supplier_name, cs.company_name 
                       FROM purchases p 
                       JOIN suppliers s ON p.supplier_id = s.id
                       LEFT JOIN company_settings cs ON 1=1
                       WHERE p.public_token = ? LIMIT 1");
$stmt->execute([$token]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) die("Invalid Token");

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = (int)($_POST['rating'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');
    
    if ($rating >= 1 && $rating <= 5) {
        $stmtIns = $pdo->prepare("INSERT INTO supplier_ratings (purchase_id, rating, feedback) VALUES (?, ?, ?)");
        $stmtIns->execute([$po['id'], $rating, $feedback]);
        $saved = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You | <?php echo htmlspecialchars($po['company_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .rating-card { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); max-width: 500px; width: 100%; text-align: center; }
        .success-icon { font-size: 4rem; color: #198754; margin-bottom: 20px; }
        .star-rating { direction: rtl; display: inline-flex; font-size: 2rem; }
        .star-rating input { display: none; }
        .star-rating label { color: #ddd; cursor: pointer; transition: color 0.2s; padding: 0 5px; }
        .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #ffc107; }
        .animate-pop { animation: popIn 0.5s ease-out; }
        @keyframes popIn { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body>

    <div class="rating-card animate-pop">
        <?php if ($saved): ?>
            <div class="py-5">
                <i class="fas fa-heart text-danger mb-3" style="font-size: 4rem;"></i>
                <h2 class="fw-bold mb-3">You're Awesome!</h2>
                <p class="text-muted">Thank you for your feedback. We look forward to doing business with you.</p>
                <div class="mt-4">
                     <a href="supplier_response.php?token=<?php echo $token; ?>" class="btn btn-outline-primary rounded-pill px-4">View Quote</a>
                </div>
            </div>
        <?php else: ?>
            <i class="fas fa-check-circle success-icon"></i>
            <h2 class="fw-bold mb-2">Quote Submitted!</h2>
            <p class="text-muted mb-4">Your response for PO <strong><?php echo $po['purchase_no']; ?></strong> has been successfully received.</p>
            
            <hr class="my-4 op-20">
            
            <form method="POST">
                <h5 class="mb-3">How was your experience using this portal?</h5>
                
                <div class="mb-3">
                    <div class="star-rating">
                        <input type="radio" id="star5" name="rating" value="5" required /><label for="star5" title="Excellent"><i class="fas fa-star"></i></label>
                        <input type="radio" id="star4" name="rating" value="4" /><label for="star4" title="Good"><i class="fas fa-star"></i></label>
                        <input type="radio" id="star3" name="rating" value="3" /><label for="star3" title="Average"><i class="fas fa-star"></i></label>
                        <input type="radio" id="star2" name="rating" value="2" /><label for="star2" title="Poor"><i class="fas fa-star"></i></label>
                        <input type="radio" id="star1" name="rating" value="1" /><label for="star1" title="Very Poor"><i class="fas fa-star"></i></label>
                    </div>
                </div>
                
                <div class="mb-3">
                    <textarea name="feedback" class="form-control bg-light border-0" rows="3" placeholder="Any additional comments? (Optional)"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill fw-bold shadow-sm">Submit Feedback</button>
            </form>
            
            <div class="mt-4">
                <a href="supplier_response.php?token=<?php echo $token; ?>" class="text-muted small text-decoration-none hover-underline"><i class="fas fa-arrow-left me-1"></i> Return to Quote</a>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
