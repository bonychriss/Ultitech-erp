import React, { useState } from 'react';
import { HiOutlineArrowLeft } from 'react-icons/hi2';

export default function ShipmentCreate({ data }) {
  const {
    suppliers = [],
    shippers = [],
    po_data = null,
    auto_description = '',
    purchase_id = null,
    error = '',
    formAction = 'create.php',
    indexUrl = 'index.php',
    tracking_default = '',
  } = data;

  const [isSubmitting, setIsSubmitting] = useState(false);
  const defaultSupplierId = (po_data && po_data.supplier_id) ? String(po_data.supplier_id) : '';
  const defaultTotalValue = (po_data && po_data.total_amount != null) ? String(po_data.total_amount) : '0.00';
  const defaultContact = (po_data && po_data.contact_number) ? String(po_data.contact_number) : '';

  const handleSubmit = () => setIsSubmitting(true);

  return (
    <div className="min-h-full w-full bg-white">
      <header className="sticky top-0 z-10 bg-white/95 backdrop-blur border-b border-slate-200">
        <div className="page-container flex flex-wrap items-center justify-between gap-3 py-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-800">Create New Shipment</h1>
            {po_data && (
              <p className="text-sm text-slate-500 mt-0.5">
                From Purchase Order: <strong>#PO-{String(po_data.id).padStart(5, '0')}</strong>
              </p>
            )}
          </div>
          <a href={indexUrl} className="btn-secondary text-sm py-1.5 px-3">
            <HiOutlineArrowLeft className="w-4 h-4" /> Back to List
          </a>
        </div>
      </header>

      <div className="page-container py-4 max-w-7xl">
        {error && (
          <div className="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">
            {error}
          </div>
        )}

        <div className="card p-8 sm:p-10 md:p-12">
          <form method="POST" action={formAction} onSubmit={handleSubmit} className="space-y-7">
            {purchase_id && <input type="hidden" name="purchase_id" value={purchase_id} />}

            {/* All fields in one grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Supplier <span className="text-red-500">*</span></label>
                <select name="supplier_id" className="input-base w-full" required defaultValue={defaultSupplierId}>
                  <option value="">Select Supplier</option>
                  {suppliers.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Invoice Number <span className="text-red-500">*</span></label>
                <input type="text" name="invoice_number" className="input-base w-full" required placeholder="e.g. INV-2025-001" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Contact Number</label>
                <input type="text" name="contact_number" className="input-base w-full" placeholder="e.g. 0086123456789" defaultValue={defaultContact} />
              </div>
              <div className="sm:col-span-2 lg:col-span-3">
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                <textarea name="description" rows={2} className="input-base w-full" placeholder="e.g. MASK, GLOVES" defaultValue={auto_description} />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Shipper / Forwarder</label>
                <select name="shipper_id" className="input-base w-full">
                  <option value="">Select Shipper</option>
                  {shippers.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Status</label>
                <select name="status" className="input-base w-full">
                  <option value="pending">PENDING</option>
                  <option value="shipped">SHIPPED</option>
                  <option value="arrived_at_port">ARRIVED AT PORT</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Est. Clearance Cost ($)</label>
                <input type="number" step="0.01" name="estimated_clearance_cost" className="input-base w-full" defaultValue="0.00" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Tracking Number</label>
                <input type="text" name="tracking_number" className="input-base w-full" defaultValue={tracking_default || 'NA'} />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Shipment Date</label>
                <input type="date" name="shipment_date" className="input-base w-full" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">ETD (Expected Departure)</label>
                <input type="date" name="etd" className="input-base w-full" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">ETA (Expected Arrival)</label>
                <input type="date" name="eta" className="input-base w-full" />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Total Packages</label>
                <input type="number" name="packages_count" className="input-base w-full" min="1" defaultValue="1" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Total CBM (m³)</label>
                <input type="number" step="0.001" name="cbm" className="input-base w-full" defaultValue="0.000" />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">Total Invoice Value ($)</label>
                <input type="number" step="0.01" name="total_value" className="input-base w-full" defaultValue={defaultTotalValue} />
              </div>
            </div>

            <div className="flex flex-wrap gap-2 pt-3 border-t border-slate-200">
              <a href={indexUrl} className="btn-secondary">Cancel</a>
              <button type="submit" className="btn-primary" disabled={isSubmitting}>
                {isSubmitting ? 'Creating…' : 'Create Shipment'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}
