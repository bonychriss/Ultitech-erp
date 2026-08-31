import React, { useCallback, useEffect, useMemo, useState } from 'react';
import '../attendance-settings.css';

function normalizeSettings(raw) {
  const s = raw || {};
  const ips = Array.isArray(s.office_ips) && s.office_ips.length ? s.office_ips.map(String) : [''];
  return {
    start_time: String(s.start_time || '09:00').slice(0, 5),
    end_time: String(s.end_time || '17:00').slice(0, 5),
    grace_period_minutes: Number(s.grace_period_minutes ?? 15),
    office_ips: ips,
    geofence_enabled: !!s.geofence_enabled,
    latitude: s.latitude != null && s.latitude !== '' ? Number(s.latitude) : null,
    longitude: s.longitude != null && s.longitude !== '' ? Number(s.longitude) : null,
    radius_meters: Number(s.radius_meters ?? 100) || 100,
  };
}

async function apiRequest(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify(body),
  });
  let data = null;
  try {
    data = await res.json();
  } catch {
    data = null;
  }
  if (!res.ok || !data) {
    throw new Error((data && data.message) || `Request failed (${res.status})`);
  }
  if (data.success === false) {
    throw new Error(data.message || 'Request failed.');
  }
  return data;
}

function showToast(type, message) {
  const text = String(message || '').trim();
  if (!text) return;

  if (typeof window !== 'undefined' && window.Swal) {
    const ok = type !== 'err';
    window.Swal.fire({
      icon: ok ? 'success' : 'error',
      title: text,
      toast: true,
      position: 'top-end',
      timer: ok ? 3200 : 4500,
      timerProgressBar: true,
      showConfirmButton: false,
      background: ok ? '#f0fdf4' : '#fef2f2',
      color: ok ? '#166534' : '#b91c1c',
      iconColor: ok ? '#22c55e' : '#ef4444',
    });
    return;
  }

  // Fallback toast if SweetAlert is unavailable
  const el = document.createElement('div');
  el.className = `att-settings-toast att-settings-toast--${type === 'err' ? 'err' : 'ok'}`;
  el.setAttribute('role', 'status');
  el.innerHTML = `<i class="fas ${type === 'err' ? 'fa-circle-xmark' : 'fa-circle-check'}" aria-hidden="true"></i><span></span>`;
  el.querySelector('span').textContent = text;
  document.body.appendChild(el);
  requestAnimationFrame(() => el.classList.add('is-show'));
  window.setTimeout(() => {
    el.classList.remove('is-show');
    window.setTimeout(() => el.remove(), 280);
  }, type === 'err' ? 4500 : 3200);
}

const SECTIONS = [
  { id: 'working-hours', label: 'Working Hours' },
  { id: 'network-security', label: 'Network' },
  { id: 'location-settings', label: 'Location' },
];

export default function AttendanceSettings({ data }) {
  const initial = data || {};
  const [form, setForm] = useState(() => normalizeSettings(initial.settings));
  const [currentIp, setCurrentIp] = useState(String(initial.current_ip || ''));
  const [links, setLinks] = useState(initial.links || {});
  const [activeSection, setActiveSection] = useState('working-hours');
  const [saving, setSaving] = useState(false);
  const [locating, setLocating] = useState(false);
  const [coordsPulse, setCoordsPulse] = useState(false);
  const [flash, setFlash] = useState(null);

  const apiUrl = links.api || initial.links?.api || '';
  const hubUrl = links.hub || initial.links?.hub || '#';

  const applyPayload = useCallback((payload) => {
    if (!payload) return;
    if (payload.settings) setForm(normalizeSettings(payload.settings));
    if (payload.current_ip != null) setCurrentIp(String(payload.current_ip));
    if (payload.links) setLinks(payload.links);
  }, []);

  useEffect(() => {
    applyPayload(initial);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    function onScroll() {
      let current = SECTIONS[0].id;
      for (const s of SECTIONS) {
        const el = document.getElementById(s.id);
        if (!el) continue;
        if (el.getBoundingClientRect().top <= 140) current = s.id;
      }
      setActiveSection(current);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  const setField = (key, value) => {
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  const updateIp = (index, value) => {
    setForm((prev) => {
      const next = [...prev.office_ips];
      next[index] = value;
      return { ...prev, office_ips: next };
    });
  };

  const addIpRow = () => {
    setForm((prev) => ({ ...prev, office_ips: [...prev.office_ips, ''] }));
  };

  const removeIpRow = (index) => {
    setForm((prev) => {
      const next = prev.office_ips.filter((_, i) => i !== index);
      return { ...prev, office_ips: next.length ? next : [''] };
    });
  };

  const useMyLocation = () => {
    if (locating) return;
    if (!navigator.geolocation) {
      setFlash({ type: 'err', message: 'Geolocation is not available in this browser.' });
      return;
    }

    const host = String(window.location.hostname || '').toLowerCase();
    const isLocalHost = host === 'localhost' || host === '127.0.0.1' || host === '::1';
    if (!window.isSecureContext && !isLocalHost) {
      setFlash({
        type: 'err',
        message: 'Location requires HTTPS (or localhost). Open this page via https:// or http://localhost/.',
      });
      return;
    }

    setLocating(true);
    setFlash({ type: 'ok', message: 'Detecting location...' });

    const applyPosition = (pos) => {
      setForm((prev) => ({
        ...prev,
        latitude: pos.coords.latitude,
        longitude: pos.coords.longitude,
      }));
      setLocating(false);
      setCoordsPulse(true);
      setFlash({ type: 'ok', message: 'Location detected.' });
      window.setTimeout(() => setCoordsPulse(false), 900);
    };

    const describeError = (err) => {
      const code = err && typeof err.code === 'number' ? err.code : null;
      if (code === 1) {
        return 'Location permission denied. Allow location for this site in the browser address bar, then try again.';
      }
      if (code === 2) {
        return 'Location unavailable. Turn on Location / GPS in Windows settings and try again.';
      }
      if (code === 3) {
        return 'Location request timed out. Try again.';
      }
      return (err && err.message) || 'Could not read your location.';
    };

    const fail = (err) => {
      setLocating(false);
      setFlash({ type: 'err', message: describeError(err) });
    };

    const tryLowAccuracy = (firstErr) => {
      navigator.geolocation.getCurrentPosition(
        applyPosition,
        (err2) => fail(err2 || firstErr),
        { enableHighAccuracy: false, timeout: 20000, maximumAge: 60000 }
      );
    };

    navigator.geolocation.getCurrentPosition(
      applyPosition,
      (err) => {
        if (err && (err.code === 2 || err.code === 3)) {
          tryLowAccuracy(err);
          return;
        }
        fail(err);
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
  };

  const onSave = async (e) => {
    e.preventDefault();
    if (!apiUrl) {
      const msg = 'Settings API URL is missing.';
      setFlash({ type: 'err', message: msg });
      showToast('err', msg);
      return;
    }
    setSaving(true);
    setFlash(null);
    try {
      const result = await apiRequest(apiUrl, {
        action: 'save',
        start_time: form.start_time,
        end_time: form.end_time,
        grace_period_minutes: Number.isFinite(form.grace_period_minutes)
          ? form.grace_period_minutes
          : 15,
        office_ips: form.office_ips,
        geofence_enabled: form.geofence_enabled ? 1 : 0,
        latitude: form.latitude,
        longitude: form.longitude,
        radius_meters: Number.isFinite(form.radius_meters) ? form.radius_meters : 100,
      });
      if (result.data) applyPayload(result.data);
      const msg = result.message || 'Attendance settings updated successfully.';
      setFlash({ type: 'ok', message: msg });
      showToast('ok', msg);
    } catch (err) {
      const msg = err.message || 'Failed to save settings.';
      setFlash({ type: 'err', message: msg });
      showToast('err', msg);
    } finally {
      setSaving(false);
    }
  };

  const onAddCurrentIp = async () => {
    if (!apiUrl) return;
    setSaving(true);
    setFlash(null);
    try {
      const result = await apiRequest(apiUrl, { action: 'add_current_ip' });
      if (result.data) applyPayload(result.data);
      const msg = result.message || 'IP added.';
      setFlash({ type: 'ok', message: msg });
      showToast('ok', msg);
    } catch (err) {
      const msg = err.message || 'Could not add current IP.';
      setFlash({ type: 'err', message: msg });
      showToast('err', msg);
    } finally {
      setSaving(false);
    }
  };

  const latDisplay = form.latitude != null && Number.isFinite(form.latitude) ? form.latitude : '';
  const lonDisplay = form.longitude != null && Number.isFinite(form.longitude) ? form.longitude : '';
  const currentIpLabel = useMemo(
    () => (currentIp ? ` (${currentIp})` : ''),
    [currentIp]
  );

  return (
    <div className="att-settings">
      <div className="att-settings__topbar">
        <a className="att-settings__back" href={hubUrl}>
          <i className="fas fa-arrow-left" aria-hidden="true" /> Back to Settings
        </a>
      </div>

      {flash ? (
        <div
          className={`att-settings__flash att-settings__flash--${flash.type === 'err' ? 'err' : 'ok'}`}
          role="status"
        >
          {flash.message}
        </div>
      ) : null}

      <form onSubmit={onSave} noValidate>
        <div className="att-settings__layout">
          <aside className="att-settings__nav">
            <ul>
              {SECTIONS.map((s) => (
                <li key={s.id}>
                  <a
                    href={`#${s.id}`}
                    className={activeSection === s.id ? 'is-active' : ''}
                    onClick={() => setActiveSection(s.id)}
                  >
                    {s.label}
                  </a>
                </li>
              ))}
            </ul>
          </aside>

          <div className="att-settings__main">
            <section className="att-settings__section" id="working-hours">
              <h2 className="att-settings__section-title">Working Hours</h2>
              <p className="att-settings__section-sub">Standard reporting and departure times for staff.</p>

              <div className="att-settings__row">
                <label className="att-settings__label" htmlFor="start_time">Work Start Time</label>
                <div>
                  <input
                    id="start_time"
                    type="time"
                    required
                    className="att-settings__input"
                    value={form.start_time}
                    onChange={(e) => setField('start_time', e.target.value)}
                  />
                </div>
              </div>

              <div className="att-settings__row">
                <label className="att-settings__label" htmlFor="end_time">Work End Time</label>
                <div>
                  <input
                    id="end_time"
                    type="time"
                    required
                    className="att-settings__input"
                    value={form.end_time}
                    onChange={(e) => setField('end_time', e.target.value)}
                  />
                </div>
              </div>

              <div className="att-settings__row">
                <label className="att-settings__label" htmlFor="grace_period">Grace Period (minutes)</label>
                <div>
                  <input
                    id="grace_period"
                    type="number"
                    required
                    min={0}
                    className="att-settings__input"
                    value={form.grace_period_minutes}
                    onChange={(e) => setField('grace_period_minutes', Number(e.target.value))}
                  />
                  <p className="att-settings__help">Allowed lateness before a record is marked as Late.</p>
                </div>
              </div>
            </section>

            <section className="att-settings__section" id="network-security">
              <h2 className="att-settings__section-title">Office Network</h2>
              <p className="att-settings__section-sub">Restrict clock-in to approved public IP addresses (optional).</p>

              <div className="att-settings__row">
                <span className="att-settings__label">Office Public IPs</span>
                <div>
                  <div className="att-settings__ip-stack">
                    {form.office_ips.map((ip, index) => (
                      <div className="att-settings__ip-row" key={`ip-${index}`}>
                        <input
                          type="text"
                          className="att-settings__input"
                          placeholder="e.g. 192.168.1.1 or 102.205.250.0/24"
                          value={ip}
                          onChange={(e) => updateIp(index, e.target.value)}
                        />
                        <button
                          type="button"
                          className="att-settings__ip-btn"
                          aria-label="Remove IP"
                          onClick={() => removeIpRow(index)}
                        >
                          <i className="fas fa-minus" aria-hidden="true" />
                        </button>
                      </div>
                    ))}
                  </div>
                  <div className="att-settings__ip-actions">
                    <button type="button" className="att-settings__chip-btn" onClick={addIpRow}>
                      <i className="fas fa-plus" aria-hidden="true" /> Add IP
                    </button>
                    <button
                      type="button"
                      className="att-settings__chip-btn"
                      disabled={saving}
                      onClick={onAddCurrentIp}
                    >
                      <i className="fas fa-network-wired" aria-hidden="true" />
                      Add my current IP{currentIpLabel}
                    </button>
                  </div>
                  <p className="att-settings__help">
                    Comma-separated IPs or CIDR/wildcard ranges. After a router/ISP IP change, enable geofencing
                    below - the first staff member who clocks in at the office with location on will register the
                    new IP automatically. Admins can also use Add my current IP while on the office network.
                  </p>
                </div>
              </div>
            </section>

            <section className="att-settings__section" id="location-settings">
              <h2 className="att-settings__section-title">Office Location (Geofencing Fallback)</h2>
              <p className="att-settings__section-sub">
                Define coordinates for geofencing validation when network check fails.
              </p>

              <div className="att-settings__geo-toggle">
                <div>
                  <p className="att-settings__geo-title">Enable geofencing fallback</p>
                  <p className="att-settings__geo-desc">
                    When off, staff must be on an approved office IP. When on, GPS at the office can unlock clock-in
                    after an ISP/IP change and auto-register the new IP.
                  </p>
                </div>
                <label className="att-settings__switch" aria-label="Enable geofencing fallback">
                  <input
                    type="checkbox"
                    checked={!!form.geofence_enabled}
                    onChange={(e) => setField('geofence_enabled', e.target.checked)}
                  />
                  <span className="att-settings__slider" />
                </label>
              </div>

              {form.geofence_enabled ? (
                <>
                  <div className="att-settings__row att-settings__row--coords">
                    <div className={`att-settings__coord${coordsPulse ? ' is-pulse' : ''}`}>
                      <label className="att-settings__label" htmlFor="latitude">Office Latitude</label>
                      <input
                        id="latitude"
                        type="number"
                        step="any"
                        className="att-settings__input"
                        placeholder="e.g. -6.7924"
                        value={latDisplay}
                        onChange={(e) => {
                          const v = e.target.value;
                          setField('latitude', v === '' ? null : Number(v));
                        }}
                      />
                    </div>
                    <div className={`att-settings__coord${coordsPulse ? ' is-pulse' : ''}`}>
                      <label className="att-settings__label" htmlFor="longitude">Office Longitude</label>
                      <input
                        id="longitude"
                        type="number"
                        step="any"
                        className="att-settings__input"
                        placeholder="e.g. 39.2723"
                        value={lonDisplay}
                        onChange={(e) => {
                          const v = e.target.value;
                          setField('longitude', v === '' ? null : Number(v));
                        }}
                      />
                    </div>
                  </div>

                  <div className="att-settings__locate-row">
                    <button
                      type="button"
                      className={`att-settings__locate-btn${locating ? ' is-locating' : ''}${coordsPulse ? ' is-found' : ''}`}
                      onClick={useMyLocation}
                      disabled={locating}
                      aria-busy={locating}
                    >
                      <span className="att-settings__locate-rings" aria-hidden="true">
                        <span />
                        <span />
                      </span>
                      <i
                        className={`fas ${locating ? 'fa-circle-notch fa-spin' : 'fa-location-arrow'}`}
                        aria-hidden="true"
                      />
                      <span>{locating ? 'Detecting...' : 'My Location'}</span>
                    </button>
                    <p className="att-settings__help att-settings__help--inline">
                      Uses your browser location to fill latitude and longitude.
                    </p>
                  </div>

                  <div className="att-settings__row">
                    <label className="att-settings__label" htmlFor="radius_meters">Allowed Radius (meters)</label>
                    <div>
                      <input
                        id="radius_meters"
                        type="number"
                        min={1}
                        className="att-settings__input"
                        placeholder="e.g. 100"
                        value={form.radius_meters}
                        onChange={(e) => setField('radius_meters', Number(e.target.value) || 100)}
                      />
                      <p className="att-settings__help">Allowed radius around coordinates for clocking in/out.</p>
                    </div>
                  </div>
                </>
              ) : null}
            </section>

            <div className="att-settings__actions">
              <a className="att-settings__cancel" href={hubUrl}>Cancel</a>
              <button type="submit" className="att-settings__save" disabled={saving}>
                {saving ? 'Saving...' : 'Save Attendance Policy'}
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  );
}
