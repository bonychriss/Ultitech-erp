<?php
// layout_designer.php
require_once 'includes/functions.php';
requireLogin();

// Handle save via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_layout') {
        $name = $_POST['name'];
        $type = $_POST['type'];
        $json = $_POST['design_json'];
        $id = $_POST['id'] ?? null;

        if ($id) {
            $stmt = $pdo->prepare("UPDATE document_layouts SET name=?, type=?, design_json=? WHERE id=?");
            $stmt->execute([$name, $type, $json, $id]);
            echo json_encode(['success' => true, 'id' => $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO document_layouts (name, type, design_json, is_active) VALUES (?, ?, ?, 0)");
            $stmt->execute([$name, $type, $json]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        }
        exit;
    }
}

$id = $_GET['id'] ?? null;
$layout = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM document_layouts WHERE id = ?");
    $stmt->execute([$id]);
    $layout = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Layout Designer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js"></script>
    <style>
        body { background: #e5e5e5; height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
        
        .designer-container { display: flex; flex: 1; overflow: hidden; }
        
        /* Sidebar Toolbox */
        .toolbox {
            width: 250px;
            background: #fff;
            border-right: 1px solid #ccc;
            padding: 15px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 10;
        }
        .toolbox-item {
            padding: 10px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: grab;
            display: flex;
            align-items: center;
            gap: 8px;
            user-select: none;
        }
        .toolbox-item:hover { background: #e9ecef; }
        .toolbox-item:hover { background: #e9ecef; }
        .toolbox-header { font-weight: bold; margin-top: 10px; font-size: 0.9rem; color: #555; text-transform: uppercase; margin-bottom: 5px; }

        /* Grid for Shapes */
        .toolbox-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 5px;
        }
        .toolbox-grid .toolbox-item {
            padding: 5px;
            text-align: center;
            justify-content: center;
            font-size: 1.2rem;
            height: 40px;
        }
        /* Hide text in grid items, show only icon */
        .toolbox-grid .toolbox-item span {
            display: none;
        }

        /* Canvas Area */
        .workspace {
            flex: 1;
            background: #a0a0a0;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: auto;
            position: relative;
            padding: 40px;
        }
        .canvas-container {
            width: 210mm;
            height: 297mm;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
            position: relative;
            transform-origin: top center;
            overflow: hidden; /* Clips elements extending outside */
        }
        
        /* Draggable Elements on Canvas */
        .design-element {
            position: absolute;
            box-sizing: border-box;
            border: 1px dashed transparent;
            cursor: pointer;
        }
        .design-element:hover { border-color: #aaa; }
        .design-element.selected { border: 2px solid #007bff; z-index: 100; }
        
        /* Properties Panel */
        .properties-panel {
            width: 300px;
            background: #fff;
            border-left: 1px solid #ccc;
            padding: 15px;
            overflow-y: auto;
        }
        .prop-group { margin-bottom: 15px; }
        .prop-label { font-size: 0.85rem; font-weight: 600; color: #555; margin-bottom: 5px; }
        
        .header-bar {
            background: #343a40;
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Resize Handles */
        .resize-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #fff;
            border: 1px solid #007bff;
            z-index: 101;
            /* Center the handle on the edge */
            transform: translate(-50%, -50%); 
        }
        /* Corners */
        .rh-tl { top: 0; left: 0; cursor: nwse-resize; }
        .rh-tr { top: 0; left: 100%; cursor: nesw-resize; }
        .rh-bl { top: 100%; left: 0; cursor: nesw-resize; }
        .rh-br { top: 100%; left: 100%; cursor: nwse-resize; }
        /* Edges (Center) */
        .rh-t { top: 0; left: 50%; cursor: ns-resize; }
        .rh-b { top: 100%; left: 50%; cursor: ns-resize; }
        .rh-l { top: 50%; left: 0; cursor: ew-resize; }
        .rh-r { top: 50%; left: 100%; cursor: ew-resize; }
    </style>
</head>
<body>

<div class="header-bar">
    <div class="d-flex align-items-center gap-3">
        <a href="layout.php" class="text-white"><i class="fas fa-arrow-left"></i></a>
        <h5 class="m-0">Layout Designer</h5>
    </div>
    <div class="d-flex align-items-center gap-2">
        <input type="text" id="layoutName" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="Layout Name" value="<?= $layout['name'] ?? 'New Invoice Layout' ?>">
        <select id="layoutType" class="form-select form-select-sm bg-dark text-white border-secondary" style="width: auto;">
            <option value="invoice" <?= ($layout['type'] ?? '') == 'invoice' ? 'selected' : '' ?>>Invoice</option>
            <option value="quote" <?= ($layout['type'] ?? '') == 'quote' ? 'selected' : '' ?>>Quote</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="saveLayout()"><i class="fas fa-save me-1"></i> Save</button>
    </div>
</div>

<div class="designer-container">
    <!-- Toolbox -->
    <div class="toolbox">
        <div class="toolbox-header">Shapes</div>
        <div class="toolbox-grid">
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="100" data-bg="#eee" data-style="rect" title="Rectangle"><i class="far fa-square"></i><span> Rectangle</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="100" data-bg="#eee" data-style="rounded_rect" title="Rounded Rectangle"><i class="far fa-square" style="border-radius:5px"></i><span> Rounded</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="circle" title="Circle/Oval"><i class="far fa-circle"></i><span> Circle</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="triangle" title="Triangle"><i class="fas fa-caret-up"></i><span> Triangle</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="triangle_right" title="Right Triangle"><i class="fas fa-play" style="transform:rotate(-90deg)"></i><span> R-Tri</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="hexagon" title="Hexagon"><i class="fas fa-cube"></i><span> Hexagon</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="octagon" title="Octagon"><i class="far fa-stop-circle"></i><span> Octagon</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="pentagon" title="Pentagon"><i class="fas fa-draw-polygon"></i><span> Pentagon</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="diamond" title="Diamond"><i class="fas fa-gem"></i><span> Diamond</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#ff69b4" data-style="heart" title="Heart"><i class="fas fa-heart"></i><span> Heart</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="50" data-default-h="100" data-bg="#ffd700" data-style="lightning" title="Lightning"><i class="fas fa-bolt"></i><span> Lightning</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="80" data-default-h="80" data-bg="#eee" data-style="moon" title="Moon/Crescent"><i class="fas fa-moon"></i><span> Moon</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#ffd700" data-style="sun" title="Sun"><i class="fas fa-sun"></i><span> Sun</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="150" data-default-h="100" data-bg="#eee" data-style="cloud" title="Cloud"><i class="fas fa-cloud"></i><span> Cloud</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="cross" title="Cross"><i class="fas fa-plus"></i><span> Cross</span></div>
        </div>
        
        <div class="toolbox-header">Arrows & Stars</div>
        <div class="toolbox-grid">
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="arrow_right" title="Arrow Right"><i class="fas fa-arrow-right"></i><span> Arrow R</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="arrow_left" title="Arrow Left"><i class="fas fa-arrow-left"></i><span> Arrow L</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="arrow_up" title="Arrow Up"><i class="fas fa-arrow-up"></i><span> Arrow U</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="arrow_down" title="Arrow Down"><i class="fas fa-arrow-down"></i><span> Arrow D</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#eee" data-style="arrow_penta" title="Notched Arrow (Pentagon)"><i class="fas fa-long-arrow-alt-right"></i><span> Notch Arr</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#ffd700" data-style="star_4" title="4-Point Star"><i class="fas fa-star-of-life"></i><span> Star 4</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#ffd700" data-style="star" title="5-Point Star"><i class="fas fa-star"></i><span> Star 5</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#ffd700" data-style="star_8" title="8-Point Star"><i class="fas fa-sun"></i><span> Star 8</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="100" data-bg="#ffd700" data-style="star_12" title="Explosion (12-Point)"><i class="fas fa-certificate"></i><span> Star 12</span></div>
        </div>
        
        <div class="toolbox-header">Decorations</div>
        <div class="toolbox-grid">
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="50" data-bg="#ccc" data-style="parallelogram" title="Parallelogram"><i class="fas fa-vector-square"></i><span> Parallelogram</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="50" data-bg="#ccc" data-style="trapezoid" title="Trapezoid"><i class="fas fa-draw-polygon"></i><span> Trapezoid</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="20" data-bg="#000" data-style="wave_top" title="Wave Top (Zigzag)"><i class="fas fa-water"></i><span> Wave Top</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="20" data-bg="#000" data-style="wave_bottom" title="Wave Bottom (Zigzag)"><i class="fas fa-water"></i><span> Wave Bottom</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="50" data-bg="#000" data-style="wave_single" title="Sine Wave (Tilde)"><i class="fas fa-wave-square"></i><span> Sine Wave</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="50" data-bg="#000" data-style="curve_bottom" title="Curve Bottom"><i class="fas fa-bezier-curve"></i><span> Curve Bot</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="50" data-bg="#eee" data-style="half_hex_top" title="Half Hexagon Top"><i class="fas fa-caret-up"></i><span> Half Hex Top</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="100" data-default-h="50" data-bg="#eee" data-style="half_hex_bottom" title="Half Hexagon Bottom"><i class="fas fa-caret-down"></i><span> Half Hex Bot</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="20" data-bg="#000" data-style="diag_bot_right" title="Diag Cut BR"><i class="fas fa-slash"></i><span> Diag BR</span></div>
            <div class="toolbox-item" draggable="true" data-type="box" data-default-w="200" data-default-h="20" data-bg="#000" data-style="diag_top_right" title="Diag Cut TR"><i class="fas fa-slash"></i><span> Diag TR</span></div>
        </div>

        <div class="toolbox-item mt-2" draggable="true" data-type="line" data-default-w="100%" data-default-h="2" data-bg="#000"><i class="fas fa-minus"></i> H-Line</div>
        
        <div class="toolbox-header">Text & Data</div>
        <div class="toolbox-item" draggable="true" data-type="text" data-default-w="200" data-default-h="40"><i class="fas fa-font"></i> Static Text</div>
        <div class="toolbox-item" draggable="true" data-type="field" data-default-w="200" data-default-h="40"><i class="fas fa-database"></i> Dynamic Data</div>
        
        <div class="toolbox-header">Structure</div>
        <div class="toolbox-item" draggable="true" data-type="logo" data-default-w="150" data-default-h="80"><i class="fas fa-image"></i> Company Logo</div>
        <div class="toolbox-item" draggable="true" data-type="table" data-default-w="100%" data-default-h="200"><i class="fas fa-table"></i> Items Table</div>
        <div class="toolbox-item" draggable="true" data-type="totals" data-default-w="300" data-default-h="150"><i class="fas fa-calculator"></i> Totals Block</div>
    </div>

    <!-- Canvas -->
    <div class="workspace">
        <div class="canvas-container" id="canvas">
            <!-- Elements will be dropped here -->
        </div>
    </div>

    <!-- Properties -->
    <div class="properties-panel" id="propPanel">
        <div class="text-center text-muted mt-5">Select an element to edit properties</div>
    </div>
</script>
<script>
    let elements = [];
    let selectedId = null;
    let nextId = 1;

    // Load initial layout if exists
    const initialJson = `<?= $layout['design_json'] ?? '[]' ?>`;
    try {
        const loaded = JSON.parse(initialJson);
        if (Array.isArray(loaded)) {
            loaded.forEach(el => addElementToCanvas(el));
            nextId = loaded.length > 0 ? Math.max(...loaded.map(e => e.id)) + 1 : 1;
        }
    } catch(e) { console.error('JSON Parse Error', e); }

    const canvas = document.getElementById('canvas');
    const propPanel = document.getElementById('propPanel');

    // Drag from Toolbox Config
    document.querySelectorAll('.toolbox-item').forEach(item => {
        item.addEventListener('dragstart', e => {
            e.dataTransfer.setData('type', item.dataset.type);
            e.dataTransfer.setData('w', item.dataset.defaultW);
            e.dataTransfer.setData('h', item.dataset.defaultH);
            e.dataTransfer.setData('bg', item.dataset.bg || 'transparent');
            e.dataTransfer.setData('style', item.dataset.style || 'rect');
        });
    });

    // Drop on Canvas
    canvas.addEventListener('dragover', e => e.preventDefault());
    canvas.addEventListener('drop', e => {
        e.preventDefault();
        const type = e.dataTransfer.getData('type');
        const rect = canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        const w = e.dataTransfer.getData('w');
        const h = e.dataTransfer.getData('h');
        
        let width = w.includes('%') ? canvas.offsetWidth * (parseFloat(w)/100) : parseFloat(w);
        let height = parseFloat(h);

        const newEl = {
            id: nextId++,
            type: type,
            x: x,
            y: y,
            w: width,
            h: height,
            bg: e.dataTransfer.getData('bg'),
            style: e.dataTransfer.getData('style'),
            flipX: false, // New Flip Property
            polyPoints: [ // Default Rect Points (TL, TR, BR, BL)
                {x:0, y:0}, {x:100, y:0}, {x:100, y:100}, {x:0, y:100}
            ],
            text: type === 'text' ? 'New Text' : '',
            field: '', // For data binding
            color: '#000000',
            fontSize: '14px',
            align: 'left'
        };
        
        addElementToCanvas(newEl);
        selectElement(newEl.id);
    });

    function addElementToCanvas(elData) {
        // Only add to array if not exists (for loading)
        if(!elements.find(e => e.id === elData.id)) elements.push(elData);

        const div = document.createElement('div');
        div.className = 'design-element';
        div.id = 'el-' + elData.id;
        div.style.left = elData.x + 'px';
        div.style.top = elData.y + 'px';
        div.style.width = elData.w + 'px';
        div.style.height = elData.h + 'px';
        div.style.backgroundColor = elData.bg;
        div.style.color = elData.color;
        div.style.fontSize = elData.fontSize;
        div.style.textAlign = elData.align;
        
        // Shape Styles
        applyShapeStyle(div, elData);

        // Content Rendering
        if (elData.type === 'text') div.innerText = elData.text;
        else if (elData.type === 'field') div.innerHTML = `{{${elData.field || 'Select Field'}}}`;
        else if (elData.type === 'logo') div.innerHTML = '<img src="../../assets/images/Untitled.jpg" style="height:100%"/>'; // Placeholder
        else if (elData.type === 'box') { /* just bg */ }
        else if (elData.type === 'line') { 
            // Default styling for line (border or bg)
            // If it's a line, we might want to ensure it looks like one even if resized
            // But removing the forced height relies on background-color to fill the div.
            // Toolbox 'line' has data-bg="#000", so it effectively is a filled box.
            // No strict override needed.
        }
        else if (elData.type === 'table') div.innerHTML = `<table style="width:100%; border:1px solid #ccc;"><thead><tr style="background:#eee"><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody><tr><td>Sample Item</td><td>1</td><td>100</td><td>100</td></tr></tbody></table>`;
        else if (elData.type === 'totals') div.innerHTML = `<div style="text-align:right">Subtotal: 100<br><strong>Total: 100</strong></div>`;

        div.addEventListener('click', (e) => {
            e.stopPropagation();
            selectElement(elData.id);
        });

        canvas.appendChild(div);

        // Make Interactable
        interact(div)
            .draggable({
                listeners: { move: dragMoveListener },
                modifiers: [
                    // interact.modifiers.restrictRect({ restriction: 'parent', endOnly: true })
                ]
            })
            .resizable({
                edges: { left: true, right: true, bottom: true, top: true },
                listeners: { move: resizeMoveListener },
                modifiers: [
                    // interact.modifiers.restrictEdges({ outer: 'parent' }),
                    interact.modifiers.restrictSize({ min: { width: 20, height: 20 } })
                ],
                infix: true
            });
    }

    function applyShapeStyle(div, elData) {
        div.style.borderRadius = '0';
        div.style.clipPath = 'none';
        
        // Apply flip
        div.style.transform = elData.flipX ? 'scaleX(-1)' : 'none';
        
        const style = elData.style;
        if (style === 'circle') div.style.borderRadius = '50%';
        else if (style === 'rounded_rect') div.style.borderRadius = '15px'; // Adjust for generic Rounded Rect
        
        else if (style === 'triangle') div.style.clipPath = 'polygon(50% 0%, 0% 100%, 100% 100%)';
        else if (style === 'triangle_right') div.style.clipPath = 'polygon(0% 0%, 0% 100%, 100% 100%)';
        else if (style === 'hexagon') div.style.clipPath = 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)';
        else if (style === 'octagon') div.style.clipPath = 'polygon(30% 0%, 70% 0%, 100% 30%, 100% 70%, 70% 100%, 30% 100%, 0% 70%, 0% 30%)';
        
        // Stars
        else if (style === 'star') div.style.clipPath = 'polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%)';
        else if (style === 'star_4') div.style.clipPath = 'polygon(50% 0%, 65% 35%, 100% 50%, 65% 65%, 50% 100%, 35% 65%, 0% 50%, 35% 35%)';
        else if (style === 'star_8') div.style.clipPath = 'polygon(50% 0%, 61% 16%, 83% 12%, 75% 33%, 93% 50%, 75% 67%, 83% 88%, 61% 84%, 50% 100%, 39% 84%, 17% 88%, 25% 67%, 7% 50%, 25% 33%, 17% 12%, 39% 16%)';
        else if (style === 'star_12') div.style.clipPath = 'polygon(50% 0%, 60% 15%, 75% 7%, 80% 20%, 95% 25%, 85% 40%, 100% 50%, 85% 60%, 95% 75%, 80% 80%, 75% 93%, 60% 85%, 50% 100%, 40% 85%, 25% 93%, 20% 80%, 5% 75%, 15% 60%, 0% 50%, 15% 40%, 5% 25%, 20% 20%, 25% 7%, 40% 15%)';
        
        else if (style === 'diamond') div.style.clipPath = 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)';
        else if (style === 'pentagon') div.style.clipPath = 'polygon(50% 0%, 100% 38%, 82% 100%, 18% 100%, 0% 38%)';
        else if (style === 'parallelogram') div.style.clipPath = 'polygon(25% 0%, 100% 0%, 75% 100%, 0% 100%)';
        else if (style === 'trapezoid') div.style.clipPath = 'polygon(20% 0%, 80% 0%, 100% 100%, 0% 100%)';
        else if (style === 'message') div.style.clipPath = 'polygon(0% 0%, 100% 0%, 100% 75%, 75% 75%, 75% 100%, 50% 75%, 0% 75%)';
        
        // Misc
        else if (style === 'heart') div.style.clipPath = 'path("M 50 90 L 90 50 A 20 20 0 0 0 50 30 A 20 20 0 0 0 10 50 Z")'; 
        // Heart is impossible with pure polygon, needs path (SVG style). Browser support is good for clip-path: path(). 
        // Let's try a polygon approx for max compat? 
        // Polygon Heart (approx):
        // else if (style === 'heart') div.style.clipPath = 'polygon(50% 25%, 65% 10%, 85% 10%, 100% 30%, 100% 50%, 50% 100%, 0% 50%, 0% 30%, 15% 10%, 35% 10%)';
        else if (style === 'heart') div.style.clipPath = 'path("M 50 15 C 60 -5 95 0 95 35 C 95 60 50 90 50 90 C 50 90 5 60 5 35 C 5 0 40 -5 50 15")'; // Scalable Path? Coordinates are 0-100%? No, path uses user units (pixels) usually or view box 0-100 if we assume 100x100?
        // Wait, clip-path: path() units are absolute values (pixels). This breaks on resize.
        // WE CANNOT USE path() easily for resizable divs without dynamic JS updating the path string based on size!
        // STICK TO POLYGON OR ELLIPSE OR INSET.
        // Heart Poly Approx:
        else if (style === 'heart') div.style.clipPath = 'polygon(50% 30%, 65% 10%, 85% 10%, 100% 35%, 100% 60%, 50% 100%, 0% 60%, 0% 35%, 15% 10%, 35% 10%)';

        else if (style === 'lightning') div.style.clipPath = 'polygon(40% 0%, 100% 0%, 60% 50%, 90% 50%, 0% 100%, 30% 50%, 0% 50%)';
        else if (style === 'moon') div.style.clipPath = 'inset(0% 20% 0% 0% round 50%)'; // Not quite crescent. 
        // Crescent is hard with clip-path. Mask is better. 
        // Let's do a polygon crescent approx (C-shape)
        // else if (style === 'moon') div.style.clipPath = 'polygon(30% 0%, 100% 0%, 100% 100%, 30% 100%, 30% 80%, 70% 50%, 30% 20%)'; // Bad
        // Just use 'circle' but maybe skip Moon if too ugly.
        // Actually, CSS radial-gradient transparency hack is best for Moon, but that's background, not clip-path.
        // Let's use a simple polygon wedge for now or skip Moon to avoid "bad" shape. 
        // Simulating "Moon" with polygon is jagged. 
        // Let's use a "D" shape (Semicircle).
        else if (style === 'moon') div.style.clipPath = 'circle(50% at 75% 50%)'; // ??? visual trick? No.
        else if (style === 'moon') div.style.clipPath = 'polygon(40% 0%, 100% 20%, 80% 50%, 100% 80%, 40% 100%, 0% 50%)'; // "Crescent-ish" polygon
        
        else if (style === 'sun') div.style.clipPath = 'polygon(50% 0%, 60% 20%, 80% 10%, 75% 30%, 100% 40%, 80% 50%, 100% 60%, 75% 70%, 80% 90%, 60% 80%, 50% 100%, 40% 80%, 20% 90%, 25% 70%, 0% 60%, 20% 50%, 0% 40%, 25% 30%, 20% 10%, 40% 20%)';
        else if (style === 'cloud') div.style.clipPath = 'polygon(25% 20%, 50% 10%, 75% 20%, 90% 40%, 90% 70%, 75% 90%, 25% 90%, 10% 70%, 10% 40%)'; // Hex-ish cloud
        
        // Arrows
        else if (style === 'arrow_right') div.style.clipPath = 'polygon(0% 20%, 60% 20%, 60% 0%, 100% 50%, 60% 100%, 60% 80%, 0% 80%)';
        else if (style === 'arrow_left') div.style.clipPath = 'polygon(40% 0%, 40% 20%, 100% 20%, 100% 80%, 40% 80%, 40% 100%, 0% 50%)';
        else if (style === 'arrow_up') div.style.clipPath = 'polygon(50% 0%, 0% 40%, 20% 40%, 20% 100%, 80% 100%, 80% 40%, 100% 40%)';
        else if (style === 'arrow_down') div.style.clipPath = 'polygon(20% 0%, 80% 0%, 80% 60%, 100% 60%, 50% 100%, 0% 60%, 20% 60%)';
        else if (style === 'arrow_penta') div.style.clipPath = 'polygon(0% 0%, 75% 0%, 100% 50%, 75% 100%, 0% 100%, 25% 50%)'; // Notched arrow
        
        else if (style === 'cross') div.style.clipPath = 'polygon(20% 0%, 0% 20%, 30% 50%, 0% 80%, 20% 100%, 50% 70%, 80% 100%, 100% 80%, 70% 50%, 100% 20%, 80% 0%, 50% 30%)';
        
        // Waves / Zigzag (Simple jagged edges)
        else if (style === 'wave_top') div.style.clipPath = 'polygon(0% 100%, 0% 20%, 5% 0%, 10% 20%, 15% 0%, 20% 20%, 25% 0%, 30% 20%, 35% 0%, 40% 20%, 45% 0%, 50% 20%, 55% 0%, 60% 20%, 65% 0%, 70% 20%, 75% 0%, 80% 20%, 85% 0%, 90% 20%, 95% 0%, 100% 20%, 100% 100%)';
        else if (style === 'wave_bottom') div.style.clipPath = 'polygon(0% 0%, 100% 0%, 100% 80%, 95% 100%, 90% 80%, 85% 100%, 80% 80%, 75% 100%, 70% 80%, 65% 100%, 60% 80%, 55% 100%, 50% 80%, 45% 100%, 40% 80%, 35% 100%, 30% 80%, 25% 100%, 20% 80%, 15% 100%, 10% 80%, 5% 100%, 0% 80%)';
        
        // Sine Wave / Tilde / Rotated 2 Shape
        else if (style === 'wave_single') div.style.clipPath = 'polygon(0% 40%, 15% 20%, 30% 0%, 45% 20%, 60% 50%, 75% 85%, 90% 100%, 100% 85%, 100% 100%, 90% 100%, 75% 100%, 60% 80%, 45% 50%, 30% 20%, 15% 0%, 0% 20%)';
        // Simpler S-shape approximation
        // Let's make it a thick ribbon ~
        else if (style === 'wave_single') div.style.clipPath = 'polygon(0% 50%, 10% 30%, 25% 10%, 40% 30%, 50% 50%, 60% 70%, 75% 90%, 90% 70%, 100% 50%, 100% 80%, 90% 100%, 75% 100%, 60% 100%, 45% 80%, 25% 40%, 10% 40%, 0% 80%)';
        
        // Revert to a clean simple sine ribbon
        else if (style === 'wave_single') div.style.clipPath = 'polygon(0% 55%, 25% 5%, 50% 55%, 75% 100%, 100% 55%, 100% 90%, 75% 135%, 50% 90%, 25% 40%, 0% 90%)';
        
        else if (style === 'curve_bottom') div.style.clipPath = 'ellipse(100% 100% at 50% 0%)';
        else if (style === 'half_hex_top') div.style.clipPath = 'polygon(25% 0%, 75% 0%, 100% 100%, 0% 100%)';
        else if (style === 'half_hex_bottom') div.style.clipPath = 'polygon(0% 0%, 100% 0%, 75% 100%, 25% 100%)';
        
        else if (style === 'diag_bot_right') div.style.clipPath = 'polygon(0 0, 100% 0, 100% 80%, 0 100%)';
        else if (style === 'diag_top_right') div.style.clipPath = 'polygon(0 0, 100% 0, 0 100%)';
        else if (style === 'custom_poly') {
            const p = elData.polyPoints || [{x:0, y:0}, {x:100, y:0}, {x:100, y:100}, {x:0, y:100}];
            div.style.clipPath = `polygon(${p[0].x}% ${p[0].y}%, ${p[1].x}% ${p[1].y}%, ${p[2].x}% ${p[2].y}%, ${p[3].x}% ${p[3].y}%)`;
        }
    }

    function dragMoveListener(event) {
        const target = event.target;
        const id = parseInt(target.id.split('-')[1]);
        const elObj = elements.find(e => e.id === id);

        // Update Model
        elObj.x += event.dx;
        elObj.y += event.dy;

        // Update DOM
        target.style.left = elObj.x + 'px';
        target.style.top = elObj.y + 'px';
    }

    function resizeMoveListener(event) {
        const target = event.target;
        const id = parseInt(target.id.split('-')[1]);
        const elObj = elements.find(e => e.id === id);

        // Update Model
        elObj.w = event.rect.width;
        elObj.h = event.rect.height;
        elObj.x += event.deltaRect.left;
        elObj.y += event.deltaRect.top;

        // Update DOM
        target.style.width = elObj.w + 'px';
        target.style.height = elObj.h + 'px';
        target.style.left = elObj.x + 'px';
        target.style.top = elObj.y + 'px';
    }
    
    // Clear selection on background click
    canvas.addEventListener('click', () => {
        document.querySelectorAll('.design-element').forEach(el => el.classList.remove('selected'));
        propPanel.innerHTML = '<div class="text-center text-muted mt-5">Select an element to edit properties</div>';
        selectedId = null;
    });

    function selectElement(id) {
        selectedId = id;
        document.querySelectorAll('.design-element').forEach(el => {
            el.classList.remove('selected');
            // Remove handles
            el.querySelectorAll('.resize-handle').forEach(h => h.remove());
        });
        
        const domEl = document.getElementById('el-' + id);
        if(domEl) {
            domEl.classList.add('selected');
            addResizeHandles(domEl);
        }

        const elObj = elements.find(e => e.id === id);
        renderProperties(elObj);
    }

    function renderProperties(el) {
        let html = `<h6 class="border-bottom pb-2">Properties (ID: ${el.id})</h6>`;
        
        // Shape Style (Box Only)
        if(el.type === 'box') {
             html += `<div class="prop-group"><label class="prop-label">Shape Style</label>
                     <select class="form-select text-dark" onchange="updateProp(${el.id}, 'style', this.value)">
                        <option value="rect" ${el.style==='rect'?'selected':''}>Rectangle</option>
                        <option value="rounded_rect" ${el.style==='rounded_rect'?'selected':''}>Rounded Rectangle</option>
                        <option value="circle" ${el.style==='circle'?'selected':''}>Circle</option>
                        <option value="triangle" ${el.style==='triangle'?'selected':''}>Triangle</option>
                        <option value="triangle_right" ${el.style==='triangle_right'?'selected':''}>Right Triangle</option>
                        <option value="hexagon" ${el.style==='hexagon'?'selected':''}>Hexagon</option>
                        <option value="octagon" ${el.style==='octagon'?'selected':''}>Octagon</option>
                        <option value="star" ${el.style==='star'?'selected':''}>Star 5-Pt</option>
                        <option value="star_4" ${el.style==='star_4'?'selected':''}>Star 4-Pt</option>
                        <option value="star_8" ${el.style==='star_8'?'selected':''}>Star 8-Pt</option>
                        <option value="star_12" ${el.style==='star_12'?'selected':''}>Star 12-Pt</option>
                        <option value="diamond" ${el.style==='diamond'?'selected':''}>Diamond</option>
                        <option value="pentagon" ${el.style==='pentagon'?'selected':''}>Pentagon</option>
                        <option value="heart" ${el.style==='heart'?'selected':''}>Heart</option>
                        <option value="lightning" ${el.style==='lightning'?'selected':''}>Lightning</option>
                        <option value="sun" ${el.style==='sun'?'selected':''}>Sun</option>
                        <option value="moon" ${el.style==='moon'?'selected':''}>Moon</option>
                        <option value="cloud" ${el.style==='cloud'?'selected':''}>Cloud</option>
                        <option value="parallelogram" ${el.style==='parallelogram'?'selected':''}>Parallelogram</option>
                        <option value="trapezoid" ${el.style==='trapezoid'?'selected':''}>Trapezoid</option>
                        <option value="message" ${el.style==='message'?'selected':''}>Message Bubble</option>
                        <option value="arrow_right" ${el.style==='arrow_right'?'selected':''}>Arrow Right</option>
                        <option value="arrow_left" ${el.style==='arrow_left'?'selected':''}>Arrow Left</option>
                        <option value="arrow_up" ${el.style==='arrow_up'?'selected':''}>Arrow Up</option>
                        <option value="arrow_down" ${el.style==='arrow_down'?'selected':''}>Arrow Down</option>
                        <option value="arrow_penta" ${el.style==='arrow_penta'?'selected':''}>Arrow Notched</option>
                        <option value="cross" ${el.style==='cross'?'selected':''}>Cross</option>
                        <option value="wave_top" ${el.style==='wave_top'?'selected':''}>Wave/Zigzag Top</option>
                        <option value="wave_bottom" ${el.style==='wave_bottom'?'selected':''}>Wave/Zigzag Bottom</option>
                        <option value="wave_single" ${el.style==='wave_single'?'selected':''}>Single Wave</option>
                        <option value="curve_bottom" ${el.style==='curve_bottom'?'selected':''}>Curve Bottom</option>
                        <option value="half_hex_top" ${el.style==='half_hex_top'?'selected':''}>Half Hexagon Top</option>
                        <option value="half_hex_bottom" ${el.style==='half_hex_bottom'?'selected':''}>Half Hexagon Bottom</option>
                        <option value="diag_bot_right" ${el.style==='diag_bot_right'?'selected':''}>Diag Cut (Bottom Right)</option>
                        <option value="diag_top_right" ${el.style==='diag_top_right'?'selected':''}>Diag Cut (Top Right)</option>
                        <option value="custom_poly" ${el.style==='custom_poly'?'selected':''}>Custom Polygon Points</option>
                     </select></div>`;
                     
             // Flip Option
             html += `<div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="flipCheck" ${el.flipX ? 'checked' : ''} onchange="updateProp(${el.id}, 'flipX', this.checked)">
                        <label class="form-check-label user-select-none" for="flipCheck">Flip Horizontal</label>
                      </div>`;
                     
             if(el.style === 'custom_poly') {
                 const p = el.polyPoints || [{x:0, y:0}, {x:100, y:0}, {x:100, y:100}, {x:0, y:100}];
                 html += `<div class="prop-group bg-light p-2 border rounded mt-2">
                            <label class="prop-label mb-1">Corner Points (%)</label>
                            <div class="d-flex gap-1 mb-1 align-items-center"><small style="width:20px">TL</small>
                                <input type="number" class="form-control form-control-sm p-0 text-center" value="${p[0].x}" onchange="updatePoly(${el.id}, 0, 'x', this.value)">
                                <input type="number" class="form-control form-control-sm p-0 text-center" value="${p[0].y}" onchange="updatePoly(${el.id}, 0, 'y', this.value)">
                            </div>
                            <div class="d-flex gap-1 mb-1 align-items-center"><small style="width:20px">TR</small>
                                <input type="number" class="form-control form-control-sm p-0 text-center" value="${p[1].x}" onchange="updatePoly(${el.id}, 1, 'x', this.value)">
                                <input type="number" class="form-control form-control-sm p-0 text-center" value="${p[1].y}" onchange="updatePoly(${el.id}, 1, 'y', this.value)">
                            </div>
                            <div class="d-flex gap-1 mb-1 align-items-center"><small style="width:20px">BR</small>
                                <input type="number" class="form-control form-control-sm p-0 text-center" value="${p[2].x}" onchange="updatePoly(${el.id}, 2, 'x', this.value)">
                                <input type="number" class="form-control form-control-sm p-0 text-center" value="${p[2].y}" onchange="updatePoly(${el.id}, 2, 'y', this.value)">
                            </div>
                            <div class="d-flex gap-1 mb-1 align-items-center"><small style="width:20px">BL</small>
                                <input type="number" class="form-control form-control-sm p-0 text-center" value="${p[3].x}" onchange="updatePoly(${el.id}, 3, 'x', this.value)">
                                <input type="number" class="form-control form-control-sm p-0 text-center" value="${p[3].y}" onchange="updatePoly(${el.id}, 3, 'y', this.value)">
                            </div>
                          </div>`;
             }
        }

        // Background Color
        html += `<div class="prop-group"><label class="prop-label">Background Color</label>
                 <input type="color" class="form-control form-control-color w-100" value="${el.bg || '#ffffff'}" onchange="updateProp(${el.id}, 'bg', this.value)"></div>`;
        
        // Text Color
        if(['text', 'field', 'totals'].includes(el.type)) {
            html += `<div class="prop-group"><label class="prop-label">Text Color</label>
                     <input type="color" class="form-control form-control-color w-100" value="${el.color || '#000000'}" onchange="updateProp(${el.id}, 'color', this.value)"></div>`;
             
             html += `<div class="prop-group"><label class="prop-label">Font Size</label>
                     <select class="form-select text-dark" onchange="updateProp(${el.id}, 'fontSize', this.value)">
                        <option value="12px" ${el.fontSize==='12px'?'selected':''}>12px</option>
                        <option value="14px" ${el.fontSize==='14px'?'selected':''}>14px</option>
                        <option value="18px" ${el.fontSize==='18px'?'selected':''}>18px</option>
                        <option value="24px" ${el.fontSize==='24px'?'selected':''}>24px</option>
                        <option value="32px" ${el.fontSize==='32px'?'selected':''}>32px</option>
                     </select></div>`;
                     
             html += `<div class="prop-group"><label class="prop-label">Align</label>
                     <select class="form-select text-dark" onchange="updateProp(${el.id}, 'align', this.value)">
                        <option value="left" ${el.align==='left'?'selected':''}>Left</option>
                        <option value="center" ${el.align==='center'?'selected':''}>Center</option>
                        <option value="right" ${el.align==='right'?'selected':''}>Right</option>
                     </select></div>`;
        }

        // Static Text
        if(el.type === 'text') {
            html += `<div class="prop-group"><label class="prop-label">Content</label>
                     <input type="text" class="form-control text-dark bg-white" value="${el.text}" oninput="updateProp(${el.id}, 'text', this.value)"></div>`;
        }

        // Data Binding
        if(el.type === 'field') {
            const fields = [
                'invoice_number', 'date', 'due_date', 
                'customer_name', 'customer_address', 'customer_tin', 'customer_vrn',
                'company_name', 'company_tin',
                'total_amount', 'balance_due'
            ];
            let opts = fields.map(f => `<option value="${f}" ${el.field===f?'selected':''}>${f}</option>`).join('');
            html += `<div class="prop-group"><label class="prop-label">Data Field</label>
                     <select class="form-select text-dark" onchange="updateProp(${el.id}, 'field', this.value)">
                        <option value="">-- Select --</option>
                        ${opts}
                     </select></div>`;
        }
        
        // Delete Button
        html += `<hr><button class="btn btn-danger btn-sm w-100" onclick="deleteElement(${el.id})"><i class="fas fa-trash"></i> Delete Element</button>`;

        propPanel.innerHTML = html;
    }

    function updatePoly(id, pointIndex, type, val) {
        const elObj = elements.find(e => e.id === id);
        if(!elObj.polyPoints) elObj.polyPoints = [{x:0, y:0}, {x:100, y:0}, {x:100, y:100}, {x:0, y:100}];
        elObj.polyPoints[pointIndex][type] = parseFloat(val);
        
        const domEl = document.getElementById('el-' + id);
        applyShapeStyle(domEl, elObj);
    }

    function updateProp(id, key, value) {
        const elObj = elements.find(e => e.id === id);
        elObj[key] = value;
        
        const domEl = document.getElementById('el-' + id);
        if(key === 'bg') domEl.style.backgroundColor = value;
        if(key === 'color') domEl.style.color = value;
        if(key === 'fontSize') domEl.style.fontSize = value;
        if(key === 'align') domEl.style.textAlign = value;
        if(key === 'text') domEl.innerText = value;
        if(key === 'field') domEl.innerHTML = `{{${value}}}`;
        
        if(key === 'style' || key === 'flipX') {
            applyShapeStyle(domEl, elObj);
            // Re-render properties to show/hide poly inputs
            if(key === 'style' && value === 'custom_poly') renderProperties(elObj);
        }
    }
    
    function deleteElement(id) {
        // Remove from DOM
        const domEl = document.getElementById('el-' + id);
        if(domEl) domEl.remove();
        
        // Remove from Model
        elements = elements.filter(e => e.id !== id);
        
        // Clear panel
        propPanel.innerHTML = '';
        selectedId = null;
    }

    function addResizeHandles(el) {
        const handles = ['tl', 'tr', 'bl', 'br', 't', 'b', 'l', 'r'];
        handles.forEach(pos => {
            const h = document.createElement('div');
            h.className = `resize-handle rh-${pos}`;
            // Prevent handle click from engaging generic element click (optional, but interact handles drag)
            el.appendChild(h);
        });
    }

    async function saveLayout() {
        const name = document.getElementById('layoutName').value;
        const type = document.getElementById('layoutType').value;
        const json = JSON.stringify(elements);
        
        const fd = new FormData();
        fd.append('action', 'save_layout');
        fd.append('name', name);
        fd.append('type', type);
        fd.append('design_json', json);
        <?php if($id): ?>fd.append('id', <?= $id ?>);<?php endif; ?>

        try {
            const res = await fetch('layout_designer.php', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.success) {
                alert('Layout Saved Successfully!');
                if(!window.location.search.includes('id=')) {
                    window.location.search = '?id=' + data.id;
                }
            }
        } catch(e) {
            alert('Error saving layout');
            console.error(e);
        }
    }
</script>

</body>
</html>
