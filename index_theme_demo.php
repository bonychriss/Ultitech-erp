<?php
// Include the layout wrapper which handles session start
require_once 'layout.php';

// Start layout with a specific title
// Note: This sidebar auto-includes 'sidebar.php' which creates the 'Theme' modal.
startLayout('Theme System Demo', 'theme');
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="card-title text-primary"><i class="bi bi-palette"></i> PHP Theme System</h2>
                <p class="lead">
                    You can now switch themes dynamically without React!
                </p>
                <hr>
                <p>
                    The current theme is stored in your session: 
                    <strong><?= htmlspecialchars($_SESSION['theme'] ?? 'Default') ?></strong>
                </p>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill"></i>
                    Click the <strong>"Appearance"</strong> link in the sidebar or use the button below to open the theme switcher.
                </div>
                
                <button class="btn btn-primary btn-lg" onclick="openThemeModal()">
                    <i class="bi bi-magic"></i> Open Theme Switcher
                </button>
            </div>
        </div>
        
        <div class="card shadow-sm">
             <div class="card-header">
                <strong>Feature Checklist</strong>
            </div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item">
                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                    <strong>5 Distinct Themes:</strong> Trust Blue, Deep Ocean, Clean White, Blue Gradient, Night Mode.
                </li>
                <li class="list-group-item">
                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                    <strong>CSS Variables:</strong> All colors are defined in <code>assets/css/sidebar_themes.css</code>.
                </li>
                <li class="list-group-item">
                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                    <strong>Smooth Transitions:</strong> Hover animations slide icons and fade backgrounds.
                </li>
                 <li class="list-group-item">
                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                    <strong>Persistent:</strong> Selection is saved in PHP Session.
                </li>
            </ul>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Interactive Elements to test theme context (layout background doesn't change, just sidebar) -->
        <div class="card text-white bg-dark mb-3">
             <div class="card-body">
                <h5 class="card-title">Dark Card</h5>
                <p class="card-text">Some layout elements remain static to show contrast.</p>
            </div>
        </div>
         <div class="card text-white bg-primary mb-3">
             <div class="card-body">
                <h5 class="card-title">Primary Card</h5>
                <p class="card-text">Matches default theme active state.</p>
            </div>
        </div>
    </div>
</div>

<?php
endLayout();
?>
