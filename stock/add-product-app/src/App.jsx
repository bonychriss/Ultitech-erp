import { useState, useRef, useEffect } from 'react';

export default function App({ init }) {
  const { autoCode, autoCodeTruck, categories, suppliers, trucksCategoryId, indexUrl, formAction } = init;
  const [registerType, setRegisterType] = useState('spare_part');
  const [imagePreviews, setImagePreviews] = useState([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const formRef = useRef(null);
  const previewUrlsRef = useRef([]);

  const isTruck = registerType === 'truck';
  const heading = isTruck ? 'Register Truck' : 'Register Spare Part';

  const handleSubmit = (e) => {
    e.preventDefault();
    if (isSubmitting) return;
    setIsSubmitting(true);
    formRef.current?.submit();
  };

  const handleImageChange = (e) => {
    const files = e.target.files;
    previewUrlsRef.current.forEach((url) => URL.revokeObjectURL(url));
    previewUrlsRef.current = [];
    setImagePreviews([]);
    if (!files?.length) return;
    const next = [];
    for (let i = 0; i < files.length; i++) {
      const file = files[i];
      if (!file.type.startsWith('image/')) continue;
      const url = URL.createObjectURL(file);
      previewUrlsRef.current.push(url);
      next.push({ url, name: file.name });
    }
    setImagePreviews(next);
  };

  useEffect(() => {
    return () => previewUrlsRef.current.forEach((url) => URL.revokeObjectURL(url));
  }, []);

  return (
    <div className="min-h-full w-full bg-slate-50">
      {/* Sticky header – compact */}
      <header className="sticky top-0 z-10 bg-white/90 backdrop-blur-sm border-b border-slate-200">
        <div className="page-container py-2.5">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 className="text-xl font-semibold text-slate-900" id="pageHeading">
              {heading}
            </h1>
            <a href={indexUrl} className="btn-secondary shrink-0">
              <span className="opacity-80">←</span> Back to List
            </a>
          </div>
        </div>
      </header>

      <div className="page-container py-6 pb-8">
        <form
          id="add-product-form"
          ref={formRef}
          method="POST"
          action={formAction}
          encType="multipart/form-data"
          onSubmit={handleSubmit}
          className="section-gap"
        >
          <input type="hidden" name="register_type" value={registerType} />

          {/* Type toggle – compact */}
          <div className="card px-4 py-2.5">
            <div className="flex flex-wrap items-center gap-3">
              <span className="form-label mb-0 text-slate-500 text-sm">Register as</span>
              <div className="inline-flex rounded-md border border-slate-200 bg-slate-50/50 p-0.5">
                <button
                  type="button"
                  onClick={() => setRegisterType('spare_part')}
                  className={`rounded px-3 py-1.5 text-sm font-medium transition-colors ${
                    !isTruck
                      ? 'bg-primary-600 text-white shadow-sm'
                      : 'text-slate-600 hover:bg-white hover:text-slate-800'
                  }`}
                >
                  Spare part
                </button>
                <button
                  type="button"
                  onClick={() => setRegisterType('truck')}
                  className={`rounded px-3 py-1.5 text-sm font-medium transition-colors ${
                    isTruck
                      ? 'bg-primary-600 text-white shadow-sm'
                      : 'text-slate-600 hover:bg-white hover:text-slate-800'
                  }`}
                >
                  Truck
                </button>
              </div>
            </div>
          </div>

          {/* Spare Part Panel */}
          <div style={{ display: isTruck ? 'none' : 'block' }}>
            <SparePartPanel
              autoCode={autoCode}
              categories={categories}
              suppliers={suppliers}
              disabled={isTruck}
            />
          </div>

          {/* Truck Panel */}
          <div style={{ display: isTruck ? 'block' : 'none' }}>
            <TruckPanel
              autoCodeTruck={autoCodeTruck}
              categories={categories}
              suppliers={suppliers}
              trucksCategoryId={trucksCategoryId}
              disabled={!isTruck}
            />
          </div>

          {/* Images (shared) – with preview so user can confirm selection */}
          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Product Images</h2>
            </div>
            <div className="p-5">
              <input
                type="file"
                name="product_images[]"
                multiple
                accept="image/jpeg,image/png,image/gif,image/webp"
                onChange={handleImageChange}
                className="block w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100 file:transition-colors"
              />
              <p className="form-hint">JPG, PNG, GIF or WebP. First image is primary.</p>
              {imagePreviews.length > 0 && (
                <div className="mt-4">
                  <p className="form-label mb-2 text-slate-600">Selected images – confirm before saving</p>
                  <div className="flex flex-wrap gap-3">
                    {imagePreviews.map((preview, index) => (
                      <div
                        key={preview.url}
                        className="relative rounded-lg border border-slate-200 bg-slate-50 overflow-hidden shadow-card"
                      >
                        <img
                          src={preview.url}
                          alt={preview.name}
                          className="w-24 h-24 sm:w-28 sm:h-28 object-cover block"
                        />
                        {index === 0 && (
                          <span className="absolute top-1 left-1 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-primary-600 text-white rounded">
                            Primary
                          </span>
                        )}
                        <p className="absolute bottom-0 left-0 right-0 bg-slate-900/70 text-white text-[10px] px-2 py-1 truncate" title={preview.name}>
                          {preview.name}
                        </p>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </section>

          <div className="flex flex-wrap items-center justify-end gap-3 pt-2">
            <a href={indexUrl} className="btn-secondary">
              Cancel
            </a>
            <button type="submit" className="btn-primary" disabled={isSubmitting}>
              {isSubmitting ? 'Saving…' : 'Save product'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function SparePartPanel({ autoCode, categories, suppliers, disabled }) {
  const inputCls = (readOnly = false) => readOnly ? 'input-readonly' : 'input-base';
  return (
    <>
      <section className="card card-basic-info">
        <div className="card-header">
          <h2 className="card-title">Basic Information</h2>
        </div>
        <div className="card-body-inner">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 field-gap">
            <div>
              <label className="form-label">Product Code <span className="text-primary-600">*</span></label>
              <input
                type="text"
                name="product_code"
                defaultValue={autoCode}
                readOnly
                required
                className={inputCls(true)}
              />
              <p className="form-hint">Auto-generated</p>
            </div>
            <div>
              <label className="form-label">Product Name <span className="text-primary-600">*</span></label>
              <input type="text" name="name" required placeholder="e.g. Brake Pad Set" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Category</label>
              <select name="category_id" disabled={disabled} className={inputCls()}>
                <option value="">— Select category —</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </div>
          </div>
          <div>
            <label className="form-label">Description</label>
            <textarea name="description" rows={3} placeholder="Optional details" disabled={disabled} className={inputCls()} />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 field-gap">
            <div>
              <label className="form-label">Compatible Truck Model</label>
              <input type="text" name="compatible_truck_model" placeholder="e.g. Volvo FH16" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">OEM Number</label>
              <input type="text" name="oem_number" placeholder="OEM part number" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Default Supplier</label>
              <select name="supplier_id" disabled={disabled} className={inputCls()}>
                <option value="">— Select supplier —</option>
                {suppliers.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="form-label">Brand</label>
              <input type="text" name="brand" placeholder="e.g. Bosch, Volvo" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Condition</label>
              <select name="part_condition" disabled={disabled} className={inputCls()}>
                <option value="new">New</option>
                <option value="used">Used</option>
                <option value="refurbished">Refurbished</option>
              </select>
            </div>
          </div>
        </div>
      </section>

      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Pricing & Stock</h2>
        </div>
        <div className="p-5">
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 field-gap">
            <div>
              <label className="form-label">Currency</label>
              <select name="currency" disabled={disabled} className={inputCls()}>
                <option value="USD">USD ($)</option>
                <option value="TZS">TZS (TSh)</option>
              </select>
            </div>
            <div>
              <label className="form-label">Buying Price</label>
              <input type="number" name="buying_price" step="0.01" min={0} defaultValue={0} disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Selling Price</label>
              <input type="number" name="unit_price" step="0.01" min={0} defaultValue={0} disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Wholesale Price</label>
              <input type="number" name="wholesale_price" step="0.01" min={0} disabled={disabled} placeholder="Optional" className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Reorder Level</label>
              <input type="number" name="reorder_level" min={0} defaultValue={10} disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Current Stock</label>
              <input type="number" name="current_stock" min={0} disabled={disabled} placeholder="0" className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Warehouse Location</label>
              <input type="text" name="location" placeholder="e.g. Shelf A1" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Unit of Measure</label>
              <select name="unit_of_measure" disabled={disabled} className={inputCls()}>
                <option value="pcs">Pieces (pcs)</option>
                <option value="set">Set</option>
                <option value="box">Box</option>
                <option value="unit">Unit</option>
              </select>
            </div>
          </div>
        </div>
      </section>
    </>
  );
}

function TruckPanel({ autoCodeTruck, categories, suppliers, trucksCategoryId, disabled }) {
  const inputCls = (readOnly = false) => readOnly ? 'input-readonly' : 'input-base';
  return (
    <>
      <input type="hidden" name="reorder_level" value="0" />
      <input type="hidden" name="current_stock" value="1" />

      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Truck Information</h2>
        </div>
        <div className="p-5 space-y-5">
          <div className="grid grid-cols-1 sm:grid-cols-2 field-gap">
            <div>
              <label className="form-label">Truck / Product Code <span className="text-primary-600">*</span></label>
              <input type="text" name="product_code" defaultValue={autoCodeTruck} readOnly required className={inputCls(true)} />
              <p className="form-hint">Auto-generated</p>
            </div>
            <div>
              <label className="form-label">Truck / Vehicle name <span className="text-primary-600">*</span></label>
              <input type="text" name="name" required placeholder="e.g. Volvo FH16 2020" disabled={disabled} className={inputCls()} />
            </div>
          </div>
          <div>
            <label className="form-label">Description</label>
            <textarea name="description" rows={2} placeholder="Optional notes" disabled={disabled} className={inputCls()} />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 field-gap">
            <div>
              <label className="form-label">Category</label>
              <select name="category_id" defaultValue={trucksCategoryId || ''} disabled={disabled} className={inputCls()}>
                <option value="">— Select —</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="form-label">Default Supplier</label>
              <select name="supplier_id" disabled={disabled} className={inputCls()}>
                <option value="">— Select supplier —</option>
                {suppliers.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </div>
          </div>
        </div>
      </section>

      <section className="card border-primary-200/60">
        <div className="card-header bg-primary-50/70 border-primary-200/60">
          <h2 className="card-title text-primary-800">Vehicle Details</h2>
        </div>
        <div className="p-5">
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 field-gap">
            <div>
              <label className="form-label">VIN</label>
              <input type="text" name="vin" placeholder="Vehicle Identification Number" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Chassis number</label>
              <input type="text" name="chassis_number" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Engine number</label>
              <input type="text" name="engine_number" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Model year</label>
              <input type="number" name="model_year" min={1900} disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Mileage (km)</label>
              <input type="number" name="mileage" step="0.1" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Color</label>
              <input type="text" name="color" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Make / brand</label>
              <input type="text" name="brand" placeholder="e.g. Volvo, Scania" disabled={disabled} className={inputCls()} />
            </div>
          </div>
        </div>
      </section>

      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Specifications</h2>
        </div>
        <div className="p-5 space-y-5">
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 field-gap">
            <div>
              <label className="form-label">Truck type</label>
              <input type="text" name="truck_type" placeholder="e.g. 6x4 Tractor" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Model number</label>
              <input type="text" name="model_number" placeholder="e.g. CA4181" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Curb weight (kg)</label>
              <input type="number" name="curb_weight_kg" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Engine model</label>
              <input type="text" name="engine_model" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Transmission</label>
              <input type="text" name="transmission_model" disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Fuel tank (L)</label>
              <input type="text" name="fuel_tank_capacity_l" disabled={disabled} className={inputCls()} />
            </div>
          </div>
          <div>
            <label className="form-label">Cab details</label>
            <textarea name="cab_details" rows={2} disabled={disabled} className={inputCls()} />
          </div>
          <div>
            <label className="form-label">Other features</label>
            <textarea name="other_features" rows={2} disabled={disabled} className={inputCls()} />
          </div>
        </div>
      </section>

      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Pricing & Location</h2>
        </div>
        <div className="p-5">
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 field-gap">
            <div>
              <label className="form-label">Currency</label>
              <select name="currency" disabled={disabled} className={inputCls()}>
                <option value="USD">USD ($)</option>
                <option value="TZS">TZS (TSh)</option>
              </select>
            </div>
            <div>
              <label className="form-label">Buying price</label>
              <input type="number" name="buying_price" step="0.01" min={0} defaultValue={0} disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Selling price</label>
              <input type="number" name="unit_price" step="0.01" min={0} defaultValue={0} disabled={disabled} className={inputCls()} />
            </div>
            <div>
              <label className="form-label">Warehouse / yard location</label>
              <input type="text" name="location" placeholder="e.g. Yard A" disabled={disabled} className={inputCls()} />
            </div>
          </div>
        </div>
      </section>
    </>
  );
}
