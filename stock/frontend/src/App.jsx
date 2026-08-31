import React, { useState } from 'react'

export default function App({ initial }){
  const [settings, setSettings] = useState(initial || {});
  const [loading, setLoading] = useState(false);
  const [msg, setMsg] = useState(null);

  const updateField = (k, v) => setSettings(prev => ({...prev, [k]: v}));

  const save = async (e) => {
    e && e.preventDefault();
    setLoading(true); setMsg(null);
    try{
      const res = await fetch('/staff/stock/settings.php?api=1', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(settings)
      });
      const data = await res.json();
      if (data.ok) setMsg({type: 'success', text: data.message || 'Saved'});
      else setMsg({type: 'error', text: data.error || 'Save failed'});
    }catch(err){ setMsg({type:'error', text: err.message}); }
    setLoading(false);
  }

  return (
    <div className="space-y-4">
      {msg && <div className={msg.type==='success' ? 'bg-green-50 border border-green-200 text-green-800 p-3 rounded' : 'bg-red-50 border border-red-200 text-red-800 p-3 rounded'}>{msg.text}</div>}
      <form onSubmit={save} className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="md:col-span-2">
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Company Name</label>
          <input className="w-full border border-gray-300 rounded px-3 py-2" value={settings.company_name||''} onChange={e=>updateField('company_name', e.target.value)} required />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Phone Number</label>
          <input className="w-full border border-gray-300 rounded px-3 py-2" value={settings.phone||''} onChange={e=>updateField('phone', e.target.value)} />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Email Address</label>
          <input type="email" className="w-full border border-gray-300 rounded px-3 py-2" value={settings.email||''} onChange={e=>updateField('email', e.target.value)} />
        </div>

        <div className="md:col-span-2">
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Street Address</label>
          <input className="w-full border border-gray-300 rounded px-3 py-2" value={settings.address||''} onChange={e=>updateField('address', e.target.value)} />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">City</label>
          <input className="w-full border border-gray-300 rounded px-3 py-2" value={settings.city||''} onChange={e=>updateField('city', e.target.value)} />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Country</label>
          <input className="w-full border border-gray-300 rounded px-3 py-2" value={settings.country||''} onChange={e=>updateField('country', e.target.value)} />
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Inventory Currency</label>
          <select className="w-full border border-gray-300 rounded px-3 py-2" value={settings.currency||'USD'} onChange={e=>updateField('currency', e.target.value)}>
            <option value="USD">USD ($)</option>
            <option value="TZS">TZS (TSh)</option>
            <option value="EUR">EUR (€)</option>
            <option value="GBP">GBP (£)</option>
          </select>
          <p className="text-xs text-gray-500 mt-1">Used for all Purchase Orders and Reports.</p>
        </div>

        <div>
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Default Payment Terms</label>
          <input className="w-full border border-gray-300 rounded px-3 py-2" value={settings.default_payment_terms||''} onChange={e=>updateField('default_payment_terms', e.target.value)} />
        </div>

        <div className="md:col-span-2">
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Bank Details / Footer Notes</label>
          <textarea className="w-full border border-gray-300 rounded px-3 py-2" rows="3" value={settings.bank_details||''} onChange={e=>updateField('bank_details', e.target.value)} />
        </div>

        <div className="md:col-span-2">
          <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Terms & Conditions</label>
          <textarea className="w-full border border-gray-300 rounded px-3 py-2" rows="6" value={settings.terms_and_conditions||''} onChange={e=>updateField('terms_and_conditions', e.target.value)} />
        </div>

        <div className="md:col-span-2 flex justify-end mt-2">
          <button className="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded" type="submit" disabled={loading}>
            <i className="fas fa-save mr-2"></i>
            {loading ? 'Saving...' : 'Save Settings'}
          </button>
        </div>
      </form>
    </div>
  )
}
