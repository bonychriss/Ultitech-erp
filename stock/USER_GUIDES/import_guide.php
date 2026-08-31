<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
requireLogin();

$active_module = 'products';
$page_title = 'Hystack AI | Import Guide';
include __DIR__ . '/../includes/header.php';
?>

<!-- Tailwind CSS -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<!-- Google Fonts: Inter -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    body { 
        font-family: 'Inter', sans-serif; 
        background-color: #fdfdfd; 
        color: #0f172a;
        -webkit-font-smoothing: antialiased;
    }
    .main-content {
        background: #fdfdfd !important;
    }
    .hystack-container { 
        max-width: 1000px; 
        margin: 0 auto; 
        padding: 4rem 2rem; 
    }
    .glass-card {
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(226, 232, 240, 0.8);
        border-radius: 24px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .glass-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 10px 10px -5px rgba(0, 0, 0, 0.02);
    }
    .ai-badge {
        background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
        color: white;
        padding: 4px 12px;
        border-radius: 100px;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .step-blob {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 1.25rem;
        position: relative;
    }
    .step-blob::after {
        content: '';
        position: absolute;
        inset: -4px;
        border: 2px solid currentColor;
        border-radius: 20px;
        opacity: 0.2;
    }
    
    /* Floating AI Assistant */
    .ai-bubble {
        position: fixed;
        bottom: 40px;
        right: 40px;
        width: 64px;
        height: 64px;
        background: #0f172a;
        border-radius: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        cursor: pointer;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        z-index: 1000;
        transition: all 0.3s ease;
    }
    .ai-bubble:hover {
        transform: scale(1.1) rotate(5deg);
        background: #1e293b;
    }
    .ai-bubble::before {
        content: "Ask Hystack AI";
        position: absolute;
        right: 80px;
        background: white;
        color: #0f172a;
        padding: 8px 16px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        opacity: 0;
        pointer-events: none;
        transition: all 0.3s ease;
    }
    .ai-bubble:hover::before {
        opacity: 1;
        right: 90px;
    }

    .pro-tag {
        color: #3b82f6;
        font-weight: 700;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }

    @media (max-width: 640px) {
        .hystack-container { padding: 2rem 1rem; }
        .ai-bubble { bottom: 20px; right: 20px; }
    }
</style>

<main class="main-content">
    <div class="hystack-container">
        
        <!-- AI Search Header -->
        <div class="text-center mb-20">
            <div class="flex justify-center mb-6">
                <span class="ai-badge">Hystack Intelligent Support</span>
            </div>
            <h1 class="text-5xl font-extrabold text-slate-900 tracking-tight mb-4">
                Master the <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-violet-600">Bulk Import</span>
            </h1>
            <p class="text-slate-500 text-lg font-light max-w-2xl mx-auto leading-relaxed">
                Unlock high-fidelity inventory management. Learn how to mass-upload spare parts, auto-register brands, and resolve duplicates in seconds.
            </p>
            
            <!-- AI Search Input Mock -->
            <div class="mt-10 relative max-w-xl mx-auto">
                <i class="fas fa-search absolute left-5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input type="text" placeholder="Ask Hystack AI: 'How do I format OEM numbers?'" class="w-full pl-14 pr-32 py-5 bg-white border border-slate-200 rounded-3xl shadow-sm focus:outline-none focus:ring-4 focus:ring-blue-100 transition-all font-light text-slate-600">
                <button class="absolute right-3 top-1/2 -translate-y-1/2 bg-blue-600 text-white px-6 py-2.5 rounded-2xl text-xs font-bold hover:bg-blue-700 shadow-lg shadow-blue-200">Ask AI</button>
            </div>
        </div>

        <!-- Guide Steps -->
        <div class="space-y-8">
            
            <!-- Step 1: Intelligent Templates -->
            <div class="glass-card p-10 flex flex-col md:flex-row gap-10">
                <div class="step-blob bg-blue-50 text-blue-600 flex-shrink-0">01</div>
                <div>
                    <div class="pro-tag mb-1">Foundational Step</div>
                    <h3 class="text-2xl font-bold text-slate-800 mb-3">Download Intelligent Templates</h3>
                    <p class="text-slate-500 leading-relaxed font-light mb-6">
                        Always start with our standardized Excel/CSV template. It contains the exact schema required for AI-powered mapping. 
                        <span class="font-bold text-slate-700 italic">Pro-Tip: Do not rename column headers.</span>
                    </p>
                    <a href="../modules/products/spare_import.php" class="inline-flex items-center gap-3 px-8 py-3 bg-slate-900 text-white rounded-2xl font-bold text-xs hover:bg-slate-800 transition-all shadow-xl">
                        <i class="fas fa-file-excel text-green-400"></i> Get Spare Part Template
                    </a>
                </div>
            </div>

            <!-- Step 2: Brand Auto-Registration -->
            <div class="glass-card p-10 flex flex-col md:flex-row gap-10">
                <div class="step-blob bg-violet-50 text-violet-600 flex-shrink-0">02</div>
                <div>
                    <div class="pro-tag mb-1">Advanced Logic</div>
                    <h3 class="text-2xl font-bold text-slate-800 mb-3">Brand Auto-Registration</h3>
                    <p class="text-slate-500 leading-relaxed font-light">
                        Our import engine is now linked to the <span class="font-bold text-slate-700">High-Fidelity Brand Module</span>. 
                        When you import a manufacturer name that isn't in the system, Hystack AI automatically registers it and categorizes it correctly. No manual setup required.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-[11px] font-bold text-violet-600 bg-violet-50 px-4 py-2 rounded-full w-fit">
                        <i class="fas fa-robot"></i> AI-ENABLED FEATURE
                    </div>
                </div>
            </div>

            <!-- Step 3: Industrial Data Mapping -->
            <div class="glass-card p-10 flex flex-col md:flex-row gap-10">
                <div class="step-blob bg-emerald-50 text-emerald-600 flex-shrink-0">03</div>
                <div>
                    <div class="pro-tag mb-1">Data Enrichment</div>
                    <h3 class="text-2xl font-bold text-slate-800 mb-3">Industrial Precision Mapping</h3>
                    <p class="text-slate-500 leading-relaxed font-light mb-4">
                        Capture the full technical profile of your inventory. Ensure your import file includes:
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl">
                            <i class="fas fa-barcode text-slate-400"></i>
                            <span class="text-xs font-bold text-slate-700">OEM Part Numbers</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl">
                            <i class="fas fa-truck-moving text-slate-400"></i>
                            <span class="text-xs font-bold text-slate-700">Truck Compatibility</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl">
                            <i class="fas fa-info-circle text-slate-400"></i>
                            <span class="text-xs font-bold text-slate-700">Part Condition</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl">
                            <i class="fas fa-dollar-sign text-slate-400"></i>
                            <span class="text-xs font-bold text-slate-700">Multi-Currency Pricing</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 4: Duplicate Detection Scanner -->
            <div class="glass-card p-10 flex flex-col md:flex-row gap-10 bg-amber-50/20 border-amber-100">
                <div class="step-blob bg-amber-100 text-amber-600 flex-shrink-0">04</div>
                <div>
                    <div class="pro-tag mb-1 text-amber-600">Integrity Check</div>
                    <h3 class="text-2xl font-bold text-slate-800 mb-3">Duplicate Detection Scanner</h3>
                    <p class="text-slate-500 leading-relaxed font-light">
                        Hystack AI runs a real-time scanner during import. If you accidentally upload items that already exist, the system will flag them with a <span class="text-amber-600 font-bold uppercase text-[10px] tracking-widest">Duplicate Conflict</span> warning. Use the **Resolve Now** feature in the product list to clean these up.
                    </p>
                </div>
            </div>

        </div>

        <!-- Support Footer -->
        <div class="mt-32 text-center p-12 bg-slate-900 rounded-[40px] text-white relative overflow-hidden shadow-2xl">
            <div class="absolute top-0 right-0 p-12 opacity-10">
                <i class="fas fa-robot text-[200px]"></i>
            </div>
            <h2 class="text-3xl font-bold mb-4">Still need help?</h2>
            <p class="text-slate-400 font-light mb-8 max-w-md mx-auto">Ask Hystack AI directly. Our intelligent assistant is trained on your specific inventory workflows.</p>
            <div class="flex justify-center gap-4">
                <button class="px-8 py-3 bg-blue-600 text-white rounded-2xl font-bold text-sm hover:bg-blue-700 transition-all shadow-xl shadow-blue-900/40">Open AI Assistant</button>
                <a href="mailto:support@roadmaster.erp" class="px-8 py-3 bg-slate-800 text-slate-300 rounded-2xl font-bold text-sm hover:bg-slate-700 transition-all">Email Support</a>
            </div>
        </div>

        <footer class="mt-20 text-center">
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.3em]">
                HYSTACK AI TECHNOLOGY • ERP MASTER GUIDE v4.0
            </div>
        </footer>

    </div>
</main>

<!-- Floating AI Assistant Bubble -->
<div class="ai-bubble group" onclick="Swal.fire({ 
    title: '<div class=\'text-2xl font-bold text-slate-800\'>Hystack AI</div>', 
    html: '<div class=\'text-sm text-slate-500 font-light\'>I am analyzing your inventory schema. How can I help you today?</div>', 
    confirmButtonText: 'Start Chat',
    customClass: { confirmButton: 'px-8 py-3 bg-slate-900 text-white rounded-2xl text-xs font-bold' }
})">
    <i class="fas fa-robot text-2xl"></i>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
