<?php
require_once '../../../includes/config.php';
if (session_status() == PHP_SESSION_NONE) session_start();
require_once '../../../includes/functions.php';
requireLogin(); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Settings | Modern Workspace</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-bg: #f8fafc;
            --border-color: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --accent-gold: #f59e0b;
            --stone-header: #f1f5f9;
            --tab-active: #2563eb;
        }

        body { 
            background-color: var(--primary-bg); 
            font-family: 'Inter', sans-serif; 
            color: var(--text-main);
            overflow-x: hidden;
        }

        .workspace-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 24px 120px;
        }

        .workspace-header {
            margin-bottom: 32px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0;
        }

        .header-title-box {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 24px;
        }

        .header-title-box h2 {
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            font-size: 1.75rem;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .tab-nav {
            display: flex;
            gap: 32px;
            margin-bottom: -1px; 
        }

        .tab-link {
            padding: 12px 4px;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tab-link:hover {
            color: var(--text-main);
        }

        .tab-link.active {
            color: var(--tab-active);
            border-bottom-color: var(--tab-active);
        }

        .glass-card {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        .section-header {
            background: var(--stone-header);
            padding: 10px 16px;
            margin: -24px -24px 24px;
            border-radius: 16px 16px 0 0;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h6 {
            margin: 0;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
        }

        .form-label {
            font-weight: 700;
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 6px;
            letter-spacing: 0.025em;
        }

        .form-control {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.875rem;
            transition: all 0.2s;
            background-color: #f8fafc;
        }

        .form-control:focus {
            border-color: var(--tab-active);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            background-color: #fff;
        }

        .layout-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .layout-item {
            cursor: pointer;
            border: 2px solid #f1f5f9;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.2s;
            background: #fff;
            text-align: center;
        }

        .layout-item:hover { border-color: #cbd5e1; }
        .layout-item.active { border-color: var(--tab-active); background: #f8fafc; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }

        .layout-img {
            width: 100%;
            height: 100px;
            object-fit: cover;
            border-bottom: 1px solid #f1f5f9;
        }

        .preview-pane {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            position: sticky;
            top: 24px;
        }

        .preview-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #94a3b8;
            letter-spacing: 1px;
            margin-bottom: 16px;
            text-align: center;
        }

        .mock-footer {
            background: white;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            border-radius: 4px;
            font-size: 10px;
            color: #334155;
            min-height: 200px;
        }

        .mock-payment-header { font-weight: 700; margin-bottom: 6px; border-bottom: 1px solid #f1f5f9; padding-bottom: 2px; text-transform: uppercase; font-size: 9px; }
        .mock-thanks { text-align: center; margin-top: 30px; font-weight: 700; font-size: 12px; }

        .save-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255,255,255,0.8);
            backdrop-filter: blur(12px);
            border-top: 1px solid var(--border-color);
            padding: 20px 0;
            z-index: 1000;
        }

        .save-bar-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        @media (max-width: 768px) {
            .tab-nav { gap: 16px; overflow-x: auto; padding-bottom: 8px; }
            .header-title-box { flex-direction: column; align-items: flex-start; gap: 16px; }
            .save-bar-inner { flex-direction: column; gap: 12px; text-align: center; }
        }
    </style>
</head>
<body>
    <?php include '../../../includes/header_employee.php'; ?>
    <div id="sales-settings-root"></div>

    <script type="text/babel">
        const { useState, useEffect } = React;

        function App() {
            const [settings, setSettings] = useState(null);
            const [loading, setLoading] = useState(true);
            const [activeTab, setActiveTab] = useState('general');
            const [saving, setSaving] = useState(false);

            useEffect(() => {
                fetch('api_settings.php')
                    .then(res => {
                        if (!res.ok) throw new Error('Network response was not ok');
                        return res.json();
                    })
                    .then(data => {
                        setSettings(data);
                        setLoading(false);
                    })
                    .catch(err => {
                        console.error('Fetch error:', err);
                        showToast('Failed to load settings. Please check your connection or login status.', 'error');
                        setLoading(false);
                    });
            }, []);


            const showToast = (msg, icon = 'success') => {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: icon,
                    title: msg,
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            };

            const handleInputChange = (e) => {
                const { name, value } = e.target;
                setSettings(prev => ({ ...prev, [name]: value }));
            };

            const handleLayoutChange = async (type, layoutId) => {
                const field = type === 'spare' ? 'spare_part_layout' : 'truck_layout';
                const newSettings = {...settings, [field]: layoutId};
                setSettings(newSettings);
                
                const formData = new FormData();
                formData.append(field, layoutId);
                
                try {
                    const res = await fetch('api_settings.php', { method: 'POST', body: formData });
                    const result = await res.json();
                    if (result.success) showToast(`${type.charAt(0).toUpperCase() + type.slice(1)} layout updated`);
                } catch (err) { showToast('Connection error', 'error'); }
            };

            const saveAll = async () => {
                setSaving(true);
                const formData = new FormData();
                Object.entries(settings).forEach(([k, v]) => {
                    if (v !== null) formData.append(k, v);
                });

                try {
                    const res = await fetch('api_settings.php', { method: 'POST', body: formData });
                    const result = await res.json();
                    if (result.success) showToast('All settings saved successfully');
                    else showToast(result.message || 'Error saving', 'error');
                } catch (err) { showToast('Server error', 'error'); }
                finally { setSaving(false); }
            };

            if (loading) return <div className="d-flex align-items-center justify-content-center vh-100"><div className="spinner-border text-primary"></div></div>;

            return (
                <div className="workspace-container">
                    <header className="workspace-header">
                        <div className="header-title-box">
                            <div>
                                <p className="text-muted small mb-1">Module Configuration</p>
                                <h2>Sales Workspace</h2>
                            </div>
                            <a href="../dashboard/index.php" className="btn btn-outline-primary btn-sm rounded-pill px-4">
                                <i className="fas fa-arrow-left me-2"></i>Dashboard
                            </a>
                        </div>
                        <nav className="tab-nav">
                            <div className={`tab-link ${activeTab === 'general' ? 'active' : ''}`} onClick={() => setActiveTab('general')}>General</div>
                            <div className={`tab-link ${activeTab === 'financials' ? 'active' : ''}`} onClick={() => setActiveTab('financials')}>Financials</div>
                            <div className={`tab-link ${activeTab === 'truck' ? 'active' : ''}`} onClick={() => setActiveTab('truck')}>Truck Layout</div>
                            <div className={`tab-link ${activeTab === 'spare' ? 'active' : ''}`} onClick={() => setActiveTab('spare')}>Spare Part Layout</div>
                        </nav>
                    </header>

                    <main className="workspace-content">
                        {activeTab === 'general' && (
                            <div className="glass-card">
                                <div className="section-header"><h6>Company Identity</h6></div>
                                <div className="row g-4">
                                    <div className="col-md-9">
                                        <div className="mb-4">
                                            <label className="form-label">Legal Company Name</label>
                                            <input name="company_name" value={settings.company_name} onChange={handleInputChange} className="form-control form-control-lg fw-bold" />
                                        </div>
                                        <div className="row g-4">
                                            <div className="col-md-6">
                                                <label className="form-label">Phone Contact</label>
                                                <input name="company_phone" value={settings.company_phone} onChange={handleInputChange} className="form-control" />
                                            </div>
                                            <div className="col-md-6">
                                                <label className="form-label">Email Address</label>
                                                <input name="company_email" value={settings.company_email} onChange={handleInputChange} className="form-control" />
                                            </div>
                                        </div>
                                    </div>
                                    <div className="col-md-3 text-center border-start ps-4">
                                        <label className="form-label">Brand Logo</label>
                                        <div className="p-3 border rounded-3 bg-light mb-3 d-flex align-items-center justify-content-center" style={{height: '100px'}}>
                                            <img src={`/assets/images/${settings.company_logo || 'logo.png'}`} className="img-fluid" style={{maxHeight:'80px'}} />
                                        </div>
                                        <button className="btn btn-sm btn-outline-dark w-100 rounded-pill">Update Logo</button>
                                    </div>
                                    <div className="col-12">
                                        <label className="form-label">Physical Registered Address</label>
                                        <textarea name="company_address" value={settings.company_address} onChange={handleInputChange} className="form-control" rows="2"></textarea>
                                    </div>
                                    <div className="col-12">
                                        <label className="form-label">Document closing message (invoices &amp; quotations)</label>
                                        <textarea
                                            name="document_footer_message"
                                            value={settings.document_footer_message ?? ''}
                                            onChange={handleInputChange}
                                            className="form-control"
                                            rows="2"
                                            placeholder="e.g. Thank you for your business"
                                        ></textarea>
                                        <p className="text-muted small mt-1 mb-0">Shown centered at the bottom of printed and PDF invoices and quotes only when this field has text. Leave empty to hide it.</p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {activeTab === 'financials' && (
                            <div className="glass-card">
                                <div className="section-header"><h6>Tax & Currency Profile</h6></div>
                                <div className="row g-4">
                                    <div className="col-md-4">
                                        <label className="form-label">TIN Number</label>
                                        <input name="company_tin" value={settings.company_tin} onChange={handleInputChange} className="form-control" />
                                    </div>
                                    <div className="col-md-4">
                                        <label className="form-label">VAT / VRN</label>
                                        <input name="company_vat" value={settings.company_vat} onChange={handleInputChange} className="form-control" />
                                    </div>
                                    <div className="col-md-4">
                                        <label className="form-label">Primary Currency</label>
                                        <input name="default_currency" value={settings.default_currency} onChange={handleInputChange} className="form-control" />
                                    </div>
                                    <div className="col-12">
                                        <div className="p-3 border rounded-3 bg-white mb-2">
                                            <div className="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div className="fw-bold text-dark" style={{fontSize: '0.875rem'}}>Enable Tax Inclusive</div>
                                                    <div className="text-muted" style={{fontSize: '0.75rem'}}>Allow calculating tax backwards from the total price</div>
                                                </div>
                                                <div className="btn-group border rounded-pill overflow-hidden bg-light p-1">
                                                    <button type="button" className={`btn btn-sm rounded-pill fw-bold ${settings.enable_tax_inclusive == 1 ? 'btn-success shadow-sm text-white' : 'btn-light border-0 text-muted'}`} style={{width: '60px', transition: 'all 0.2s'}} onClick={() => setSettings(prev => ({...prev, enable_tax_inclusive: 1}))}>ON</button>
                                                    <button type="button" className={`btn btn-sm rounded-pill fw-bold ${settings.enable_tax_inclusive != 1 ? 'btn-danger shadow-sm text-white' : 'btn-light border-0 text-muted'}`} style={{width: '60px', transition: 'all 0.2s'}} onClick={() => setSettings(prev => ({...prev, enable_tax_inclusive: 0}))}>OFF</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="p-3 border rounded-3 bg-white">
                                            <div className="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div className="fw-bold text-dark" style={{fontSize: '0.875rem'}}>Enable Tax Exclusive</div>
                                                    <div className="text-muted" style={{fontSize: '0.75rem'}}>Allow adding tax on top of the product subtotal</div>
                                                </div>
                                                <div className="btn-group border rounded-pill overflow-hidden bg-light p-1">
                                                    <button type="button" className={`btn btn-sm rounded-pill fw-bold ${settings.enable_tax_exclusive == 1 ? 'btn-success shadow-sm text-white' : 'btn-light border-0 text-muted'}`} style={{width: '60px', transition: 'all 0.2s'}} onClick={() => setSettings(prev => ({...prev, enable_tax_exclusive: 1}))}>ON</button>
                                                    <button type="button" className={`btn btn-sm rounded-pill fw-bold ${settings.enable_tax_exclusive != 1 ? 'btn-danger shadow-sm text-white' : 'btn-light border-0 text-muted'}`} style={{width: '60px', transition: 'all 0.2s'}} onClick={() => setSettings(prev => ({...prev, enable_tax_exclusive: 0}))}>OFF</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="col-12">
                                        <label className="form-label">Payment Methods / Bank Details (General)</label>
                                        <textarea name="bank_details" value={settings.bank_details} onChange={handleInputChange} className="form-control" rows="3"></textarea>
                                    </div>
                                </div>
                            </div>
                        )}

                        {(activeTab === 'truck' || activeTab === 'spare') && (
                            <div className="row g-4">
                                <div className="col-xl-8">
                                    <div className="glass-card">
                                        <div className="section-header"><h6>Select Active Layout</h6></div>
                                        <div className="layout-grid">
                                            {(activeTab === 'spare' ? [1,2,3] : [1]).map(id => (
                                                <div 
                                                    key={id}
                                                    className={`layout-item ${settings[activeTab === 'spare' ? 'spare_part_layout' : 'truck_layout'] == id ? 'active' : ''}`}
                                                    onClick={() => handleLayoutChange(activeTab, id)}
                                                >
                                                    <img src={`/assets/images/layouts/layout_${activeTab === 'truck' ? '3' : id}.png`} className="layout-img" />
                                                    <div className="p-3">
                                                        <span className="fw-bold small d-block mb-1">{activeTab.toUpperCase()} {id}</span>
                                                        <span className="text-muted" style={{fontSize: '0.65rem'}}>
                                                            {activeTab === 'truck' ? 'Watermark' : (id === 1 ? 'Premium' : id === 2 ? 'Classic' : 'Minimalist')}
                                                        </span>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                        
                                        <div className="section-header mt-4"><h6>Categorized Footer Details</h6></div>
                                        <div className="row g-4">
                                            <div className="col-md-6">
                                                <label className="form-label">Payment Instructions</label>
                                                <textarea 
                                                    name={`${activeTab}_payment_details`} 
                                                    value={settings[`${activeTab}_payment_details`] ?? ''} 
                                                    onChange={handleInputChange}
                                                    className="form-control" rows="4"
                                                    placeholder="Enter bank names and account numbers..."
                                                ></textarea>
                                            </div>
                                            <div className="col-md-6">
                                                <label className="form-label">Terms & Policy Agreement</label>
                                                <textarea 
                                                    name={`${activeTab}_terms`} 
                                                    value={settings[`${activeTab}_terms`] ?? ''} 
                                                    onChange={handleInputChange}
                                                    className="form-control" rows="4"
                                                    placeholder="Standard terms and conditions..."
                                                ></textarea>
                                            </div>
                                            <div className="col-md-6">
                                                <label className="form-label">Quote Validity</label>
                                                <input name={`${activeTab}_validity`} value={settings[`${activeTab}_validity`] ?? ''} onChange={handleInputChange} className="form-control" placeholder="e.g. Valid for 10 days" />
                                            </div>
                                            <div className="col-md-6">
                                                <label className="form-label">Closing Appreciation Note</label>
                                                <input name={`${activeTab}_thanks_note`} value={settings[`${activeTab}_thanks_note`] ?? ''} onChange={handleInputChange} className="form-control" placeholder="e.g. Thank you for Choosing Roadmaster" />
                                            </div>
                                            <div className="col-12">
                                                <label className="form-label">Return & Refund Policy (Footer Minor)</label>
                                                <input name={`${activeTab}_return_policy`} value={settings[`${activeTab}_return_policy`] ?? ''} onChange={handleInputChange} className="form-control" />
                                            </div>
                                            {activeTab === 'truck' && (
                                                <div className="col-12">
                                                    <label className="form-label">Truck Remarks (Appears on Second Page)</label>
                                                    <textarea 
                                                        name="truck_remarks" 
                                                        value={settings.truck_remarks ?? ''} 
                                                        onChange={handleInputChange} 
                                                        className="form-control" 
                                                        rows="4"
                                                        placeholder="Specific remarks for truck invoices/quotations..."
                                                    ></textarea>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="col-xl-4">
                                    <div className="preview-pane">
                                        <div className="preview-label">Document Footer Preview</div>
                                        <div className="mock-footer">
                                            <div className="mock-payment-header" style={{color: '#000'}}>Payment details</div>
                                            <div className="mb-3" style={{whiteSpace: 'pre-line', fontSize: '9px', lineHeight: '1.4'}}>
                                                {settings[`${activeTab}_payment_details`] || 'Select a payment method...'}
                                            </div>
                                            
                                            <div style={{fontSize: '8px', color: '#64748b', lineHeight: '1.3'}}>
                                                <div className="mb-1">{settings[`${activeTab}_terms`]}</div>
                                                <div className="fw-bold">{settings[`${activeTab}_validity`]}</div>
                                            </div>
                                            
                                            <div className="mock-thanks" style={{color: '#000'}}>
                                                {settings[`${activeTab}_thanks_note`] || 'Thank you'}
                                                <div style={{fontSize: '7px', fontWeight: '400', color: '#94a3b8', marginTop: '6px'}}>{settings[`${activeTab}_return_policy`]}</div>
                                            </div>
                                        </div>
                                        <div className="mt-4 p-3 bg-white border border-dashed rounded-3 text-center small text-muted">
                                            <i className="fas fa-magic me-2"></i> Preview updates as you type
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </main>

                    <div className="save-bar">
                        <div className="save-bar-inner">
                            <div className="text-muted small">
                                <i className="fas fa-cloud-upload-alt me-2 text-success"></i> Changes are staged. Click save to commit.
                            </div>
                            <div className="d-flex gap-2">
                                <button className="btn btn-primary px-5 py-2 fw-bold rounded-pill shadow" onClick={saveAll} disabled={saving}>
                                    {saving ? <><span className="spinner-border spinner-border-sm me-2"></span>Saving...</> : 'Save All Settings'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('sales-settings-root'));
        root.render(<App />);
    </script>
</body>
</html>

