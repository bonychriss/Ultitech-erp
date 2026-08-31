import React, { useState, useRef } from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlineBuildingOffice2,
  HiOutlineInformationCircle,
  HiOutlineCheckCircle,
  HiOutlinePhoto,
} from 'react-icons/hi2';

const CURRENCIES = ['USD', 'TZS', 'EUR', 'GBP', 'KES'];
const PAYMENT_TERMS = ['Net 30', 'Net 15', 'Net 45', 'Due on receipt', 'COD', 'Custom'];

const defaultSettings = {
  company_name: '',
  company_logo: '',
  phone: '',
  email: '',
  address: '',
  city: '',
  country: '',
  bank_details: '',
  terms_and_conditions: '',
  currency: 'USD',
  default_payment_terms: 'Net 30',
};

export default function Settings({ data }) {
  const {
    settings: initialSettings = defaultSettings,
    baseUrl = '/staff/stock/',
    apiUrl = '/staff/stock/settings.php?api=1',
    assetsImagesUrl = '/staff/assets/images/',
    dashboardUrl = '/staff/stock/dashboard.php',
    success: initialSuccess = '',
    error: initialError = '',
  } = data;

  const [form, setForm] = useState({ ...defaultSettings, ...initialSettings });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [logoUploading, setLogoUploading] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(initialSuccess);
  const [saveError, setSaveError] = useState(initialError);
  const logoInputRef = useRef(null);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
    setSaveError('');
  };

  const showSoftSuccess = (message) => {
    if (typeof window !== 'undefined' && window.Swal) {
      window.Swal.fire({
        icon: 'success',
        title: message,
        toast: true,
        position: 'top-end',
        timer: 3000,
        timerProgressBar: true,
        showConfirmButton: false,
        background: '#f0fdf4',
        color: '#166534',
        iconColor: '#22c55e',
      });
    } else {
      setSaveSuccess(message);
    }
  };

  const handleLogoChange = async (e) => {
    const file = e.target.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    setLogoUploading(true);
    setSaveError('');
    try {
      const fd = new FormData();
      fd.append('logo', file);
      const res = await fetch(apiUrl, { method: 'POST', body: fd });
      const json = await res.json();
      if (json.ok && json.company_logo) {
        setForm((prev) => ({ ...prev, company_logo: json.company_logo }));
        showSoftSuccess(json.message || 'Logo uploaded. It will appear in the sidebar and on documents.');
      } else {
        setSaveError(json.error || 'Logo upload failed.');
      }
    } catch (err) {
      setSaveError(err.message || 'Upload failed.');
    } finally {
      setLogoUploading(false);
      if (logoInputRef.current) logoInputRef.current.value = '';
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsSubmitting(true);
    setSaveSuccess('');
    setSaveError('');
    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
      });
      const json = await res.json();
      if (json.ok) {
        showSoftSuccess('Settings saved successfully.');
      } else {
        setSaveError(json.error || 'Failed to save settings.');
      }
    } catch (err) {
      setSaveError(err.message || 'Network error. Please try again.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="min-h-full w-full bg-white">
      <header className="sticky top-0 z-10 bg-white/95 backdrop-blur border-b border-slate-200">
        <div className="page-container flex flex-wrap items-center justify-between gap-3 py-3">
          <h1 className="text-xl font-semibold text-slate-800">Company Settings</h1>
          <a href={dashboardUrl} className="btn-secondary inline-flex items-center gap-2">
            <HiOutlineArrowLeft className="w-4 h-4" /> Back to Dashboard
          </a>
        </div>
      </header>

      <div className="page-container py-4">
        {saveSuccess && (
          <div className="mb-4 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-2">
            <HiOutlineCheckCircle className="w-5 h-5 flex-shrink-0" /> {saveSuccess}
          </div>
        )}
        {saveError && (
          <div className="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">
            {saveError}
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2">
            <div className="card overflow-hidden">
              <div className="card-header flex items-center gap-2">
                <HiOutlineBuildingOffice2 className="w-5 h-5 text-slate-600" />
                Organization Details
              </div>
              <div className="p-4 sm:p-6">
                <form onSubmit={handleSubmit} className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Company Logo</label>
                    <p className="text-xs text-slate-500 mb-2">Used in the sidebar and on documents (invoices, vouchers, etc.).</p>
                    <div className="flex flex-wrap items-start gap-4">
                      <div className="flex flex-col items-center gap-2">
                        {form.company_logo ? (
                          <img src={`${assetsImagesUrl}${form.company_logo}?t=${Date.now()}`} alt="Company logo" className="h-20 w-auto max-w-[180px] object-contain border border-slate-200 rounded-lg bg-white p-1" onError={(e) => { e.target.style.display = 'none'; }} />
                        ) : (
                          <div className="h-20 w-32 border border-dashed border-slate-300 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400">
                            <HiOutlinePhoto className="w-8 h-8" />
                          </div>
                        )}
                        <input ref={logoInputRef} type="file" accept="image/*" onChange={handleLogoChange} disabled={logoUploading} className="text-sm text-slate-600 file:mr-2 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-primary-100 file:text-primary-700 file:text-sm file:font-medium hover:file:bg-primary-200" />
                        {logoUploading && <span className="text-xs text-slate-500">Uploading…</span>}
                      </div>
                    </div>
                  </div>
                  <div>
                    <label htmlFor="company_name" className="block text-sm font-medium text-slate-700 mb-1">Company Name</label>
                    <input type="text" id="company_name" name="company_name" value={form.company_name} onChange={handleChange} className="input-base" placeholder="Company name" />
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label htmlFor="phone" className="block text-sm font-medium text-slate-700 mb-1">Phone</label>
                      <input type="text" id="phone" name="phone" value={form.phone} onChange={handleChange} className="input-base" placeholder="Phone" />
                    </div>
                    <div>
                      <label htmlFor="email" className="block text-sm font-medium text-slate-700 mb-1">Email</label>
                      <input type="email" id="email" name="email" value={form.email} onChange={handleChange} className="input-base" placeholder="email@example.com" />
                    </div>
                  </div>
                  <div>
                    <label htmlFor="address" className="block text-sm font-medium text-slate-700 mb-1">Address</label>
                    <input type="text" id="address" name="address" value={form.address} onChange={handleChange} className="input-base" placeholder="Street address" />
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label htmlFor="city" className="block text-sm font-medium text-slate-700 mb-1">City</label>
                      <input type="text" id="city" name="city" value={form.city} onChange={handleChange} className="input-base" placeholder="City" />
                    </div>
                    <div>
                      <label htmlFor="country" className="block text-sm font-medium text-slate-700 mb-1">Country</label>
                      <input type="text" id="country" name="country" value={form.country} onChange={handleChange} className="input-base" placeholder="Country" />
                    </div>
                  </div>
                  <div>
                    <label htmlFor="bank_details" className="block text-sm font-medium text-slate-700 mb-1">Bank Details</label>
                    <textarea id="bank_details" name="bank_details" rows={3} value={form.bank_details} onChange={handleChange} className="input-base min-h-[80px]" placeholder="Bank name, account number, etc." />
                  </div>
                  <div>
                    <label htmlFor="terms_and_conditions" className="block text-sm font-medium text-slate-700 mb-1">Terms &amp; Conditions</label>
                    <textarea id="terms_and_conditions" name="terms_and_conditions" rows={4} value={form.terms_and_conditions} onChange={handleChange} className="input-base min-h-[100px]" placeholder="Default terms for documents" />
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label htmlFor="currency" className="block text-sm font-medium text-slate-700 mb-1">Currency</label>
                      <select id="currency" name="currency" value={form.currency} onChange={handleChange} className="input-base">
                        {CURRENCIES.map((c) => (
                          <option key={c} value={c}>{c}</option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label htmlFor="default_payment_terms" className="block text-sm font-medium text-slate-700 mb-1">Default Payment Terms</label>
                      <select id="default_payment_terms" name="default_payment_terms" value={form.default_payment_terms} onChange={handleChange} className="input-base">
                        {[...PAYMENT_TERMS, ...(form.default_payment_terms && !PAYMENT_TERMS.includes(form.default_payment_terms) ? [form.default_payment_terms] : [])].map((t) => (
                          <option key={t} value={t}>{t}</option>
                        ))}
                      </select>
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-2 pt-2">
                    <button type="submit" className="btn-primary" disabled={isSubmitting}>
                      {isSubmitting ? 'Saving…' : 'Save Settings'}
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <div className="lg:col-span-1">
            <div className="rounded-lg border border-sky-200 bg-sky-50 p-4 shadow-sm">
              <h3 className="font-semibold text-slate-800 flex items-center gap-2 mb-2">
                <HiOutlineInformationCircle className="w-5 h-5 text-sky-600" /> Tip
              </h3>
              <p className="text-sm text-slate-700 leading-relaxed">
                These details are used across all generated documents, including Purchase Orders, Invoices, and Quotes.
              </p>
              <p className="text-sm text-slate-700 mt-3">
                <strong>Currency:</strong> Sets the symbol and code used for all financial values in the stock module.
              </p>
              <p className="text-sm text-slate-700 mt-2">
                Ensure the email address is valid as it will be used as the &quot;Reply-To&quot; address for system emails.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
