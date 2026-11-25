import React, { useEffect, useMemo, useRef, useState } from 'react';
import { BriefcaseBusiness, Clock3, UsersRound } from 'lucide-react';
import { getGeoPosition, postAttendanceAction } from '../api';
import {
  beginCreateCredential,
  beginGetAssertion,
  guessDeviceLabel,
  isWebAuthnSupported,
  serializeAssertion,
  serializeAttestation,
} from '../webauthn';
import '../attendance-records.css';

function formatTimeHm(value) {
  if (!value) return '--:--';
  const str = String(value).trim();
  const timePart = str.includes(' ') ? str.split(' ').pop() : str.includes('T') ? str.split('T').pop() : str;
  const parts = String(timePart).split(':');
  if (parts.length >= 2) {
    return `${parts[0].padStart(2, '0')}:${parts[1].padStart(2, '0')}`;
  }
  return str;
}

function formatDisplayTime(raw) {
  if (!raw) return '';
  const normalized = String(raw).includes('T') ? raw : `1970-01-01T${raw}`;
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return String(raw);
  return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

const DEFAULT_WEATHER_COORDS = { latitude: -6.7924, longitude: 39.2083 }; // Dar es Salaam

function weatherFromCode(code) {
  const c = Number(code);
  if (c === 0) return { label: 'Clear', icon: 'fa-sun' };
  if (c === 1 || c === 2) return { label: 'Partly cloudy', icon: 'fa-cloud-sun' };
  if (c === 3) return { label: 'Overcast', icon: 'fa-cloud' };
  if (c === 45 || c === 48) return { label: 'Fog', icon: 'fa-smog' };
  if (c >= 51 && c <= 67) return { label: 'Rain', icon: 'fa-cloud-rain' };
  if (c >= 71 && c <= 77) return { label: 'Snow', icon: 'fa-snowflake' };
  if (c >= 80 && c <= 82) return { label: 'Showers', icon: 'fa-cloud-showers-heavy' };
  if (c >= 95) return { label: 'Storm', icon: 'fa-bolt' };
  return { label: 'Weather', icon: 'fa-cloud' };
}

function useLocalWeather() {
  const [weather, setWeather] = useState(null);

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();

    async function load(lat, lon) {
      const url = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current=temperature_2m,weather_code&timezone=auto`;
      const res = await fetch(url, { signal: controller.signal });
      if (!res.ok) throw new Error('weather fetch failed');
      const json = await res.json();
      const temp = json?.current?.temperature_2m;
      const code = json?.current?.weather_code;
      if (temp == null || cancelled) return;
      const meta = weatherFromCode(code);
      setWeather({
        tempC: Math.round(Number(temp)),
        label: meta.label,
        icon: meta.icon,
      });
    }

    async function start() {
      let lat = DEFAULT_WEATHER_COORDS.latitude;
      let lon = DEFAULT_WEATHER_COORDS.longitude;
      try {
        if (typeof navigator !== 'undefined' && navigator.geolocation) {
          const pos = await new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(resolve, reject, {
              enableHighAccuracy: false,
              timeout: 4000,
              maximumAge: 30 * 60 * 1000,
            });
          });
          lat = pos.coords.latitude;
          lon = pos.coords.longitude;
        }
      } catch (_) {
        // Fall back to Dar es Salaam.
      }
      try {
        await load(lat, lon);
      } catch (_) {
        if (!cancelled) setWeather(null);
      }
    }

    start();
    const refreshId = window.setInterval(start, 30 * 60 * 1000);
    return () => {
      cancelled = true;
      controller.abort();
      window.clearInterval(refreshId);
    };
  }, []);

  return weather;
}

function useLiveClock(timeZone) {
  const [clock, setClock] = useState(() => ({
    hour: 12,
    minute: 0,
    second: 0,
    nowLabel: '',
    dateLabel: '',
    nowMs: Date.now(),
  }));

  useEffect(() => {
    const tick = () => {
      const now = new Date();
      const timeFmt = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        hour12: false,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      });
      const dateFmt = new Intl.DateTimeFormat('en-US', {
        timeZone,
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
      });
      const parts = timeFmt.formatToParts(now);
      let H = 0;
      let M = 0;
      let S = 0;
      parts.forEach((p) => {
        if (p.type === 'hour') H = parseInt(p.value, 10) || 0;
        if (p.type === 'minute') M = parseInt(p.value, 10) || 0;
        if (p.type === 'second') S = parseInt(p.value, 10) || 0;
      });
      setClock({
        hour: H,
        minute: M,
        second: S,
        nowLabel: `${String(H).padStart(2, '0')}:${String(M).padStart(2, '0')}:${String(S).padStart(2, '0')}`,
        dateLabel: dateFmt.format(now),
        nowMs: now.getTime(),
      });
    };
    tick();
    const id = window.setInterval(tick, 1000);
    return () => window.clearInterval(id);
  }, [timeZone]);

  return clock;
}

function formatClockParts(hour, minute, second, use24Hour) {
  const minutes = String(minute).padStart(2, '0');
  const seconds = String(second).padStart(2, '0');
  if (use24Hour) {
    return {
      hours: String(hour).padStart(2, '0'),
      minutes,
      seconds,
      period: null,
    };
  }
  const period = hour >= 12 ? 'PM' : 'AM';
  const displayHour = hour % 12 === 0 ? 12 : hour % 12;
  return {
    hours: String(displayHour).padStart(2, '0'),
    minutes,
    seconds,
    period,
  };
}

function parseTimeToSeconds(value) {
  const str = String(value || '').trim();
  if (!str) return null;
  const timePart = str.includes(' ') ? str.split(' ').pop() : str.includes('T') ? str.split('T').pop() : str;
  const bits = String(timePart).split(':');
  if (bits.length < 2) return null;
  const h = parseInt(bits[0], 10);
  const m = parseInt(bits[1], 10);
  const s = parseInt(bits[2] || '0', 10);
  if ([h, m, s].some((n) => Number.isNaN(n))) return null;
  return ((h * 60) + m) * 60 + s;
}

function elapsedSeconds(timeIn, timeOut, hour, minute, second) {
  const inSec = parseTimeToSeconds(timeIn);
  if (inSec === null) return 0;
  let outSec;
  if (timeOut) {
    outSec = parseTimeToSeconds(timeOut);
    if (outSec === null) return 0;
  } else {
    outSec = ((hour * 60) + minute) * 60 + second;
  }
  let diff = outSec - inSec;
  if (diff < 0) diff += 24 * 3600;
  return diff;
}

function formatElapsedCompact(timeIn, timeOut, hour, minute, second) {
  if (!timeIn) return '0h 00m';
  const diff = elapsedSeconds(timeIn, timeOut, hour, minute, second);
  const totalMinutes = Math.floor(diff / 60);
  const hrs = Math.floor(totalMinutes / 60);
  const mins = String(totalMinutes % 60).padStart(2, '0');
  return `${hrs}h ${mins}m`;
}

function nameInitials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase();
  return `${parts[0][0] || ''}${parts[parts.length - 1][0] || ''}`.toUpperCase();
}

function buildTeamHoursLeaderboard(rows, dailyTargetHours, hour, minute, second, currentUserId) {
  const target = Math.max(Number(dailyTargetHours) || 8, 0.1);
  return (Array.isArray(rows) ? rows : [])
    .map((row) => {
      const clockedIn = Boolean(row?.time_in) || row?.clocked_in === true;
      const stillIn = clockedIn && !row?.time_out;
      const liveHours = clockedIn
        ? elapsedSeconds(row.time_in, row.time_out, hour, minute, second) / 3600
        : 0;
      const hours = clockedIn
        ? (!stillIn && row?.total_hours != null ? Number(row.total_hours) || 0 : liveHours)
        : 0;
      const progress = Math.max(0, Math.min(100, Math.round((hours / target) * 100)));
      const name = row.full_name || row.username || 'Teammate';
      return {
        key: `${row.user_id || 0}-${row.id || row.time_in || name}`,
        userId: Number(row.user_id || 0),
        name,
        initial: nameInitials(name),
        department: row.department || '',
        hours,
        progress,
        hitTarget: clockedIn && hours >= target,
        stillIn,
        clockedIn,
        timeIn: row.time_in || null,
        timeOut: row.time_out || null,
        isMe: Number(row.user_id || 0) === Number(currentUserId || 0),
      };
    })
    .sort((a, b) => {
      if (Number(b.clockedIn) !== Number(a.clockedIn)) return Number(b.clockedIn) - Number(a.clockedIn);
      if (b.hours !== a.hours) return b.hours - a.hours;
      return String(a.name).localeCompare(String(b.name));
    });
}

function buildMonthOptions(monthsBack = 12) {
  const options = [];
  const now = new Date();
  for (let i = 0; i < monthsBack; i += 1) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    options.push({
      value,
      label: d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }),
    });
  }
  return options;
}

function currentMonthValue() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export default function AttendanceDesk({ data }) {
  const [state, setState] = useState(() => {
    const months =
      Array.isArray(data.historyMonths) && data.historyMonths.length
        ? data.historyMonths
        : buildMonthOptions(12);
    const month = data.historyMonth || currentMonthValue();
    const label =
      data.historyMonthLabel ||
      months.find((m) => m.value === month)?.label ||
      '';
    return {
      todayRecord: data.todayRecord || null,
      todayPresent: Array.isArray(data.todayPresent) ? data.todayPresent : [],
      teamBoard: Array.isArray(data.teamBoard) && data.teamBoard.length
        ? data.teamBoard
        : (Array.isArray(data.todayPresent) ? data.todayPresent : []),
      history: data.history || [],
      historyMonth: month,
      historyMonthLabel: label,
      historyMonths: months,
      stats: data.stats || {},
      pendingTasks: data.pendingTasks || [],
      isIpAllowed: Boolean(data.isIpAllowed),
      currentIp: data.currentIp || '',
      biometric: data.biometric || {
        required: false,
        enrolled: false,
        count: 0,
        max: 2,
        devices: [],
        library_ok: true,
        secure_ok: true,
        rp_id: '',
      },
      message: data.message || '',
      msgType: data.msgType || '',
      clockInSuccess: data.clockInSuccess || null,
      clockOutSuccess: data.clockOutSuccess || null,
    };
  });

  const [planOpen, setPlanOpen] = useState(false);
  const [reviewOpen, setReviewOpen] = useState(false);
  const [newTasks, setNewTasks] = useState(['']);
  const [completedIds, setCompletedIds] = useState(() => new Set());
  const [busy, setBusy] = useState(false);
  const [enrollBusy, setEnrollBusy] = useState(false);
  const [enrollReady, setEnrollReady] = useState(false);
  const [successDismissed, setSuccessDismissed] = useState(false);
  const [phoneToast, setPhoneToast] = useState(null);
  const [enrollToastDismissed, setEnrollToastDismissed] = useState(false);
  const [isMobileClock, setIsMobileClock] = useState(() => {
    try {
      return window.matchMedia('(max-width: 768px)').matches;
    } catch (_) {
      return false;
    }
  });
  const webAuthnOk = isWebAuthnSupported();
  const assertPublicKeyRef = useRef(null);
  const registerPublicKeyRef = useRef(null);
  const phoneToastShownRef = useRef(false);

  useEffect(() => {
    let mq;
    try {
      mq = window.matchMedia('(max-width: 768px)');
    } catch (_) {
      return undefined;
    }
    const sync = () => setIsMobileClock(!!mq.matches);
    sync();
    if (typeof mq.addEventListener === 'function') mq.addEventListener('change', sync);
    else if (typeof mq.addListener === 'function') mq.addListener(sync);
    return () => {
      if (typeof mq.removeEventListener === 'function') mq.removeEventListener('change', sync);
      else if (typeof mq.removeListener === 'function') mq.removeListener(sync);
    };
  }, []);

  const links = data.links || {};
  const profileSecurityUrl = links.profileSecurity || '';
  const user = data.user || {};
  const shift = data.shift || {};
  const timeZone = data.timeZone || 'Africa/Dar_es_Salaam';
  const dateSummary = data.dateSummary || '';
  const clock = useLiveClock(timeZone);
  const { dateLabel, hour, minute, second, nowMs } = clock;
  const weather = useLocalWeather();
  const wallpapers = data.wallpapers || {};
  // One lock-screen image per calendar day (stable for the whole day).
  const dailyWallpaper = useMemo(() => {
    const fromBoot = Array.isArray(wallpapers.slides) ? wallpapers.slides : [];
    const named = [
      wallpapers.morning || wallpapers.sunrise || '',
      wallpapers.noon || '',
      wallpapers.dusk || wallpapers.sunset || '',
    ];
    const seen = new Set();
    const list = [];
    [...fromBoot, ...named].forEach((raw) => {
      const url = String(raw || '').trim();
      if (!url || seen.has(url)) return;
      seen.add(url);
      list.push(url);
    });
    if (list.length === 0) return '';
    const dayKeySource = dateLabel || String(Date.now());
    let hash = 0;
    for (let i = 0; i < dayKeySource.length; i += 1) {
      hash = ((hash << 5) - hash + dayKeySource.charCodeAt(i)) | 0;
    }
    return list[Math.abs(hash) % list.length];
  }, [wallpapers, dateLabel]);

  const clockCardStyle = dailyWallpaper
    ? {
        '--att-clock-photo': `url('${dailyWallpaper}')`,
        backgroundColor: '#0f172a',
      }
    : undefined;

  const todayRecord = state.todayRecord;
  const isOut = todayRecord && todayRecord.time_out;
  const isIn = todayRecord && todayRecord.time_in && !todayRecord.time_out;
  const canClockIn = !todayRecord;
  const canClockOut = Boolean(isIn);
  const dailyTargetHours = Number(shift.dailyTargetHours || 8) || 8;
  const timeParts = formatClockParts(hour, minute, second, true);
  const elapsedLabel = formatElapsedCompact(
    todayRecord?.time_in,
    todayRecord?.time_out,
    hour,
    minute,
    second
  );
  const todayHoursLive = todayRecord?.time_in
    ? elapsedSeconds(todayRecord.time_in, todayRecord.time_out, hour, minute, second) / 3600
    : 0;
  const todayHours = isOut && todayRecord?.total_hours != null
    ? Number(todayRecord.total_hours) || 0
    : todayHoursLive;
  const todayOt = Number(todayRecord?.overtime_hours ?? 0) || 0;
  const progressPercent = Math.max(
    0,
    Math.min(100, Math.round((todayHours / Math.max(dailyTargetHours, 0.1)) * 100))
  );
  const teamToday = Array.isArray(state.todayPresent) ? state.todayPresent : [];
  const teamBoard = Array.isArray(state.teamBoard) && state.teamBoard.length
    ? state.teamBoard
    : teamToday;
  const teamActiveNow = teamToday.filter((row) => row && row.time_in && !row.time_out).length;
  const teamLeaderboard = useMemo(
    () => buildTeamHoursLeaderboard(
      teamBoard,
      dailyTargetHours,
      hour,
      minute,
      second,
      user.id
    ),
    [teamBoard, dailyTargetHours, hour, minute, second, user.id]
  );

  useEffect(() => {
    if (!state.clockInSuccess && !state.clockOutSuccess) return undefined;
    const isMobile = window.matchMedia('(max-width: 768px)').matches;
    if (isMobile) document.body.classList.add('att-success-sheet-open');
    return () => document.body.classList.remove('att-success-sheet-open');
  }, [state.clockInSuccess, state.clockOutSuccess]);

  const statusMeta = useMemo(() => {
    if (isOut) {
      return {
        tone: 'out',
        label: `Out at ${formatTimeHm(todayRecord.time_out)}`,
      };
    }
    if (isIn) {
      return {
        tone: 'online',
        label: 'Online Now',
      };
    }
    return {
      tone: 'standby',
      label: 'Standby',
    };
  }, [isIn, isOut, todayRecord]);

  function openPlan() {
    setNewTasks(['']);
    setPlanOpen(true);
  }

  function openReview() {
    setCompletedIds(new Set());
    setReviewOpen(true);
  }

  function dismissSuccess() {
    setSuccessDismissed(true);
    document.body.classList.remove('att-success-sheet-open');
  }

  function applyPayload(payload, extras = {}) {
    if (!payload) return;
    setState((cur) => ({
      ...cur,
      todayRecord: payload.todayRecord ?? cur.todayRecord,
      todayPresent: Array.isArray(payload.todayPresent) ? payload.todayPresent : (cur.todayPresent || []),
      teamBoard: Array.isArray(payload.teamBoard)
        ? payload.teamBoard
        : (Array.isArray(payload.todayPresent) ? payload.todayPresent : (cur.teamBoard || [])),
      history: payload.history ?? cur.history,
      historyMonth: payload.historyMonth ?? cur.historyMonth,
      historyMonthLabel: payload.historyMonthLabel ?? cur.historyMonthLabel,
      historyMonths: payload.historyMonths ?? cur.historyMonths,
      stats: payload.stats ?? cur.stats,
      pendingTasks: payload.pendingTasks ?? cur.pendingTasks,
      isIpAllowed: payload.isIpAllowed ?? cur.isIpAllowed,
      currentIp: payload.currentIp ?? cur.currentIp,
      biometric: payload.biometric ?? cur.biometric,
      message: extras.message ?? '',
      msgType: extras.msgType ?? '',
      clockInSuccess: extras.clockInSuccess ?? null,
      clockOutSuccess: extras.clockOutSuccess ?? null,
    }));
    setSuccessDismissed(false);
  }

  async function prefetchAssertOptions() {
    const bio = state.biometric || {};
    if (!bio.required || !bio.enrolled || !webAuthnOk || !isMobileClock) {
      assertPublicKeyRef.current = null;
      return;
    }
    try {
      const options = await postAttendanceAction({ action: 'webauthn_assert_options' });
      if (options.success && options.publicKey) {
        assertPublicKeyRef.current = options.publicKey;
      }
    } catch (_) {
      assertPublicKeyRef.current = null;
    }
  }

  async function prefetchRegisterOptions() {
    const bio = state.biometric || {};
    if (!bio.required || bio.enrolled || !webAuthnOk || !isMobileClock) {
      registerPublicKeyRef.current = null;
      setEnrollReady(false);
      return;
    }
    setEnrollReady(false);
    try {
      const options = await postAttendanceAction({ action: 'webauthn_register_options' });
      if (options.success && options.publicKey) {
        registerPublicKeyRef.current = options.publicKey;
        setEnrollReady(true);
      } else {
        registerPublicKeyRef.current = null;
        setEnrollReady(false);
      }
    } catch (_) {
      registerPublicKeyRef.current = null;
      setEnrollReady(false);
    }
  }

  useEffect(() => {
    prefetchAssertOptions();
    prefetchRegisterOptions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state.biometric?.required, state.biometric?.enrolled, webAuthnOk, isMobileClock]);

  function beginBiometricIfNeeded() {
    const bio = state.biometric || {};
    if (!bio.required) return null;
    if (!bio.enrolled) {
      throw new Error('Enroll phone unlock below, then clock in.');
    }
    if (!webAuthnOk) {
      throw new Error('Open attendance on your enrolled smartphone to verify with Face ID, fingerprint, or passcode.');
    }
    const publicKey = assertPublicKeyRef.current;
    if (!publicKey) {
      throw new Error('Biometric challenge is not ready yet. Wait a second and try again.');
    }
    // Start Face ID in the same tap as the button — required on iPhone Safari.
    const promise = beginGetAssertion(publicKey);
    assertPublicKeyRef.current = null;
    return promise;
  }

  function enrollPhoneUnlock(event) {
    if (event && typeof event.preventDefault === 'function') event.preventDefault();
    const bio = state.biometric || {};
    if (!bio.required || bio.enrolled || enrollBusy) return;

    if (!webAuthnOk) {
      setState((cur) => ({
        ...cur,
        message: 'Open attendance on your smartphone to enroll Face ID, fingerprint, or passcode.',
        msgType: 'danger',
      }));
      return;
    }

    const publicKey = registerPublicKeyRef.current;
    if (!publicKey) {
      setState((cur) => ({
        ...cur,
        message: 'Still preparing phone unlock. Wait a moment, then try again.',
        msgType: 'danger',
      }));
      prefetchRegisterOptions();
      return;
    }

    let createPromise;
    try {
      // Must start in the same tap as the button (iPhone Safari).
      createPromise = beginCreateCredential(publicKey);
    } catch (err) {
      setState((cur) => ({
        ...cur,
        message: err instanceof Error ? err.message : 'Could not start phone unlock enrollment.',
        msgType: 'danger',
      }));
      prefetchRegisterOptions();
      return;
    }

    registerPublicKeyRef.current = null;
    setEnrollReady(false);
    setEnrollBusy(true);

    createPromise
      .then((credential) =>
        postAttendanceAction({
          action: 'webauthn_register',
          device_label: guessDeviceLabel(),
          credential: serializeAttestation(credential),
        })
      )
      .then((result) => {
        if (!result.success) {
          throw new Error(result.message || 'Phone unlock enrollment failed.');
        }
        applyPayload(result.data || {});
        setState((cur) => ({
          ...cur,
          message: result.message || 'Phone unlock enrolled. You can clock in now.',
          msgType: 'success',
        }));
        // Continue into clock-in plan once enrollment succeeds.
        if (!result.data?.todayRecord) {
          setTimeout(() => {
            setNewTasks(['']);
            setPlanOpen(true);
          }, 280);
        }
      })
      .catch((err) => {
        setState((cur) => ({
          ...cur,
          message: err instanceof Error ? err.message : 'Phone unlock enrollment failed.',
          msgType: 'danger',
        }));
        prefetchRegisterOptions();
      })
      .finally(() => {
        setEnrollBusy(false);
      });
  }

  async function submitClockIn() {
    const tasks = newTasks.map((t) => t.trim()).filter(Boolean);
    if (tasks.length === 0 && state.pendingTasks.length === 0) {
      window.alert('Please enter at least one task for today.');
      return;
    }
    let assertionPromise = null;
    try {
      assertionPromise = beginBiometricIfNeeded();
    } catch (err) {
      setState((cur) => ({
        ...cur,
        message: err instanceof Error ? err.message : 'Clock in failed.',
        msgType: 'danger',
      }));
      prefetchAssertOptions();
      return;
    }
    setPlanOpen(false);
    setBusy(true);
    try {
      const webauthn = assertionPromise
        ? serializeAssertion(await assertionPromise)
        : null;
      const geo = await getGeoPosition();
      const result = await postAttendanceAction({
        action: 'clock_in',
        latitude: geo.latitude,
        longitude: geo.longitude,
        new_tasks: tasks,
        history_month: state.historyMonth,
        webauthn,
      });
      if (!result.success) {
        applyPayload(result.data, { message: result.message || 'Clock in failed.', msgType: 'danger' });
        prefetchAssertOptions();
        return;
      }
      applyPayload(result.data, { clockInSuccess: result.clockInSuccess || null });
      prefetchAssertOptions();
    } catch (err) {
      setState((cur) => ({
        ...cur,
        message: err instanceof Error ? err.message : 'Clock in failed.',
        msgType: 'danger',
      }));
      prefetchAssertOptions();
    } finally {
      setBusy(false);
    }
  }

  async function submitClockOut() {
    let assertionPromise = null;
    try {
      assertionPromise = beginBiometricIfNeeded();
    } catch (err) {
      setState((cur) => ({
        ...cur,
        message: err instanceof Error ? err.message : 'Clock out failed.',
        msgType: 'danger',
      }));
      prefetchAssertOptions();
      return;
    }
    setReviewOpen(false);
    setBusy(true);
    try {
      const webauthn = assertionPromise
        ? serializeAssertion(await assertionPromise)
        : null;
      const geo = await getGeoPosition();
      const result = await postAttendanceAction({
        action: 'clock_out',
        latitude: geo.latitude,
        longitude: geo.longitude,
        completed_task_ids: Array.from(completedIds),
        history_month: state.historyMonth,
        webauthn,
      });
      if (!result.success) {
        applyPayload(result.data, { message: result.message || 'Clock out failed.', msgType: 'danger' });
        prefetchAssertOptions();
        return;
      }
      applyPayload(result.data, { clockOutSuccess: result.clockOutSuccess || null });
      prefetchAssertOptions();
    } catch (err) {
      setState((cur) => ({
        ...cur,
        message: err instanceof Error ? err.message : 'Clock out failed.',
        msgType: 'danger',
      }));
      prefetchAssertOptions();
    } finally {
      setBusy(false);
    }
  }

  const showInSuccess = state.clockInSuccess && !successDismissed;
  const showOutSuccess = state.clockOutSuccess && !successDismissed;
  const stats = state.stats || {};
  const biometric = state.biometric || {};
  const bioRequired = !!biometric.required;
  const bioEnrolled = !!biometric.enrolled;
  const usePhoneForClock = bioRequired && !isMobileClock;
  const showMobileEnroll = bioRequired && !bioEnrolled && isMobileClock;
  const showEnrollToast = showMobileEnroll && !enrollToastDismissed;
  const showHeroClockActions = !usePhoneForClock;
  const alreadyClockedToday = Boolean(todayRecord?.time_in);

  useEffect(() => {
    if (!showMobileEnroll) setEnrollToastDismissed(false);
  }, [showMobileEnroll]);

  useEffect(() => {
    // Phone nudge only before first clock-in; hide once clocked in or dismissed.
    if (!usePhoneForClock || alreadyClockedToday) {
      setPhoneToast(null);
      return undefined;
    }
    if (phoneToastShownRef.current) return undefined;
    setPhoneToast({
      body: 'Clock in and out easily from your phone, just unlock it to get started.',
    });
    return undefined;
  }, [usePhoneForClock, alreadyClockedToday]);

  return (
    <div className="att-shell att-page-clock">
      <div className="att-main">
        <div className="att-top-bar">
          <div className="att-title-wrap">
            <h1>Attendance</h1>
            <p className="att-sub-info att-sub-info--inline">
              <i className="fas fa-calendar-alt att-me-2 att-text-slate" />
              <strong>{dateSummary}</strong>
              <span className="att-sub-sep">-</span>
              Clock in/out and see who&apos;s in today
            </p>
          </div>
        </div>

        {showInSuccess ? (
          <ClockInSuccessOverlay data={state.clockInSuccess} onDismiss={dismissSuccess} />
        ) : null}
        {showOutSuccess ? (
          <ClockOutSuccessOverlay data={state.clockOutSuccess} onDismiss={dismissSuccess} />
        ) : null}

        <div className="att-grid">
          <div className="att-col-left">
            <div
              className={`clock-card-v2 clock-card-v2--time-only${showMobileEnroll ? ' clock-card-v2--photo-hero' : ''}`}
              style={clockCardStyle}
              aria-label="Time card"
            >
              <div className="clock-card-v2-photo" aria-hidden="true" />
              <div className="clock-card-v2-fade" aria-hidden="true" />
              <div className="clock-card-v2-inner">
                <div className="clock-card-v2-identity">
                  <div className="clock-card-v2-copy">
                    <div className="clock-card-v2-time-row" aria-live="polite">
                      <time className="clock-card-v2-time" dateTime={new Date(nowMs).toISOString()}>
                        {timeParts.hours}:{timeParts.minutes}
                        <span className="clock-card-v2-seconds">:{timeParts.seconds}</span>
                      </time>
                      {timeParts.period ? (
                        <span className="clock-card-v2-period">{timeParts.period}</span>
                      ) : null}
                    </div>
                    <div className="clock-card-v2-meta">
                      <span className="clock-card-v2-date">
                        <i className="fas fa-calendar-day" aria-hidden="true" />
                        {dateLabel || dateSummary || 'Today'}
                      </span>
                      {weather ? (
                        <>
                          <span className="clock-card-v2-meta-sep" aria-hidden="true" />
                          <span className="clock-card-v2-weather" title={weather.label}>
                            <i className={`fas ${weather.icon}`} aria-hidden="true" />
                            <span className="clock-card-v2-temp">{weather.tempC}°C</span>
                            <span className="clock-card-v2-weather-label">{weather.label}</span>
                          </span>
                        </>
                      ) : null}
                      {statusMeta.tone !== 'standby' ? (
                        <>
                          <span className="clock-card-v2-meta-sep" aria-hidden="true" />
                          <span className={`clock-card-v2-status is-${statusMeta.tone}`}>
                            <i className="clock-card-v2-status-dot" aria-hidden="true" />
                            {statusMeta.label}
                            {todayRecord?.time_in ? (
                              <span className="clock-card-v2-elapsed-inline"> · {elapsedLabel}</span>
                            ) : null}
                          </span>
                        </>
                      ) : null}
                    </div>
                    {!state.isIpAllowed ? (
                      <div className="att-alert">
                        <div className="att-alert-title">
                          <i className="fas fa-exclamation-triangle" /> Out of office network
                        </div>
                        <p className="att-m-0" style={{ opacity: 0.9 }}>
                          Your IP: <strong>{state.currentIp}</strong>
                          <br />
                          Connect to the office network or enable GPS location to clock in or out.
                        </p>
                      </div>
                    ) : null}
                  </div>
                </div>
              </div>
            </div>

            {showHeroClockActions && !showMobileEnroll ? (
              <div className="att-clock-actions att-clock-actions--below-card">
                {canClockIn ? (
                  <button
                    type="button"
                    className="att-glass-btn"
                    disabled={busy}
                    onClick={openPlan}
                  >
                    {busy
                      ? 'Processing...'
                      : bioRequired
                        ? 'Clock in with phone unlock'
                        : 'Clock in'}
                  </button>
                ) : null}
                {canClockOut ? (
                  <button
                    type="button"
                    className="att-btn-clock-out"
                    disabled={busy}
                    onClick={openReview}
                  >
                    {busy
                      ? 'Processing...'
                      : bioRequired
                        ? 'Clock out with phone unlock'
                        : 'Clock out'}
                  </button>
                ) : null}
                {!canClockIn && !canClockOut ? (
                  <button type="button" className="att-glass-btn att-glass-btn--muted" disabled>
                    Shift complete
                  </button>
                ) : null}
              </div>
            ) : null}

            {phoneToast && usePhoneForClock && !alreadyClockedToday ? (
              <div className="att-desk-toast att-desk-toast--below att-desk-toast--compact is-show" role="status" aria-live="polite">
                <div className="att-desk-toast-icon" aria-hidden="true">
                  <i className="fas fa-mobile-screen-button" />
                </div>
                <div className="att-desk-toast-copy">
                  <span>{phoneToast.body}</span>
                </div>
                <button
                  type="button"
                  className="att-desk-toast-close"
                  aria-label="Dismiss"
                  onClick={() => {
                    phoneToastShownRef.current = true;
                    setPhoneToast(null);
                  }}
                >
                  <i className="fas fa-times" aria-hidden="true" />
                </button>
              </div>
            ) : null}

            {showEnrollToast ? (
              <div
                className="att-desk-toast att-desk-toast--below att-desk-toast--compact att-desk-toast--enroll-line is-show"
                role="status"
                aria-live="polite"
              >
                <div className="att-desk-toast-icon att-desk-toast-icon--fp" aria-hidden="true">
                  <i className="fas fa-fingerprint" />
                </div>
                <div className="att-desk-toast-copy">
                  <span>
                    <strong>Set up security</strong>
                    {webAuthnOk ? (
                      <>
                        {' — '}
                        <button
                          type="button"
                          className="att-desk-toast-link"
                          disabled={enrollBusy || !enrollReady}
                          onClick={enrollPhoneUnlock}
                        >
                          {enrollBusy
                            ? 'Waiting…'
                            : enrollReady
                              ? 'Setup'
                              : 'Getting ready…'}
                        </button>
                      </>
                    ) : (
                      <>
                        {' — '}
                        {profileSecurityUrl ? (
                          <a className="att-desk-toast-link" href={profileSecurityUrl}>Setup</a>
                        ) : (
                          'Open on your phone'
                        )}
                      </>
                    )}
                  </span>
                </div>
                <button
                  type="button"
                  className="att-desk-toast-close"
                  aria-label="Dismiss"
                  onClick={() => setEnrollToastDismissed(true)}
                >
                  <i className="fas fa-times" aria-hidden="true" />
                </button>
              </div>
            ) : null}

            {showInSuccess ? (
              <div className="att-clockin-success" role="status" aria-live="polite">
                <div className="att-clockin-success-head">
                  <div className="att-clockin-success-icon">
                    <i className="fas fa-check" />
                  </div>
                  <div>
                    <p className="att-clockin-success-title" style={{ margin: 0 }}>
                      You&apos;re in!
                    </p>
                    <p className="att-clockin-success-sub" style={{ margin: 0 }}>
                      {state.clockInSuccess.time_in_display}
                      {' - '}
                      {(state.clockInSuccess.new_tasks || 0) + (state.clockInSuccess.carried_over || 0)} task
                      {(state.clockInSuccess.new_tasks || 0) + (state.clockInSuccess.carried_over || 0) === 1
                        ? ''
                        : 's'}{' '}
                      planned today
                    </p>
                  </div>
                </div>
              </div>
            ) : null}

            {showOutSuccess ? (
              <div className="att-clockin-success att-clockout-success" role="status" aria-live="polite">
                <div className="att-clockin-success-head">
                  <div
                    className="att-clockin-success-icon"
                    style={{ background: '#f97316', boxShadow: '0 4px 12px rgba(249,115,22,0.4)' }}
                  >
                    <i className="fas fa-sign-out-alt" />
                  </div>
                  <div>
                    <p className="att-clockin-success-title" style={{ margin: 0, color: '#9a3412' }}>
                      You&apos;re out!
                    </p>
                    <p className="att-clockin-success-sub" style={{ margin: 0 }}>
                      {state.clockOutSuccess.time_out_display}
                      {' - '}
                      {Number(state.clockOutSuccess.total_hours || 0).toFixed(1)}h worked
                      {' - '}
                      {state.clockOutSuccess.completed || 0} task
                      {(state.clockOutSuccess.completed || 0) === 1 ? '' : 's'} done
                    </p>
                  </div>
                </div>
              </div>
            ) : null}

          </div>

          <div className="att-col-right">
            {state.message ? (
              <div className={`att-alert-banner is-${state.msgType || 'danger'}`}>{state.message}</div>
            ) : null}

            <div className="att-kpi-grid att-kpi-grid--three" aria-label="Today's shift metrics">
              <div className="att-kpi-tile att-kpi-tile--progress">
                <div className="att-kpi-tile-top">
                  <div className="att-kpi-icon att-kpi-icon--hours" aria-hidden="true">
                    <Clock3 size={16} strokeWidth={2} />
                  </div>
                  <div className="att-kpi-body">
                    <div className="att-kpi-label">Hours worked</div>
                    <div className="att-kpi-value is-hours">{todayHours.toFixed(1)}h</div>
                  </div>
                  <span className="att-kpi-aside">
                    {progressPercent}% of {dailyTargetHours.toFixed(1)}h
                  </span>
                </div>
                <div className="att-kpi-progress" aria-hidden="true">
                  <span style={{ width: `${progressPercent}%` }} />
                </div>
              </div>
              <div className="att-kpi-tile">
                <div className="att-kpi-tile-top">
                  <div className="att-kpi-icon att-kpi-icon--ot" aria-hidden="true">
                    <BriefcaseBusiness size={16} strokeWidth={2} />
                  </div>
                  <div className="att-kpi-body">
                    <div className="att-kpi-label">Overtime</div>
                    <div className="att-kpi-value is-ot">
                      {(todayOt > 0 ? todayOt : Number(stats.total_ot || 0)).toFixed(1)}h
                    </div>
                  </div>
                  <span className="att-kpi-aside">
                    {todayOt > 0 || Number(stats.total_ot || 0) > 0
                      ? 'Extra shift time'
                      : 'Within regular shift'}
                  </span>
                </div>
                <p className="att-kpi-foot">
                  Accrues after {dailyTargetHours.toFixed(1)} net hours
                </p>
              </div>
              <div className="att-kpi-tile">
                <div className="att-kpi-tile-top">
                  <div className="att-kpi-icon att-kpi-icon--team" aria-hidden="true">
                    <UsersRound size={16} strokeWidth={2} />
                  </div>
                  <div className="att-kpi-body">
                    <div className="att-kpi-label">Team logged today</div>
                    <div className="att-kpi-value is-team">{teamToday.length}</div>
                  </div>
                  <span className="att-kpi-aside is-active">
                    {teamActiveNow} active now
                  </span>
                </div>
                <p className="att-kpi-foot">Live check-ins across the desk</p>
              </div>
            </div>

            <div className="att-desk-bottom-row">
            <section className="att-present-card" aria-label="Logged in today">
              <div className="att-present-header">
                <h3 className="att-present-title">Logged in today</h3>
                <span className="att-present-subtitle">
                  {teamToday.length
                    ? `${teamToday.length} person${teamToday.length === 1 ? '' : 's'}`
                    : 'No one yet'}
                </span>
              </div>
              {teamToday.length === 0 ? (
                <div className="att-desk-empty att-present-empty">
                  <div className="att-desk-empty-icon" aria-hidden="true">
                    <i className="fas fa-user-clock" />
                  </div>
                  <p className="att-desk-empty-title">No one has clocked in yet today</p>
                  <p className="att-desk-empty-sub">
                    People who clock in will appear here for today.
                  </p>
                </div>
              ) : (
                <div className="att-present-list">
                  {teamToday.map((row) => {
                    const stillIn = Boolean(row.time_in) && !row.time_out;
                    const isMe = Number(row.user_id) === Number(user.id || 0);
                    const name = row.full_name || row.username || '—';
                    return (
                      <div
                        key={`${row.user_id}-${row.id || row.time_in || ''}`}
                        className={`att-present-item${isMe ? ' is-me' : ''}${stillIn ? ' is-active' : ''}`}
                      >
                        <div className="att-present-avatar" aria-hidden="true">
                          {nameInitials(name)}
                        </div>
                        <div className="att-present-info">
                          <div className="att-present-name">
                            {name}
                            {isMe ? <span className="att-present-you">You</span> : null}
                          </div>
                          <div className="att-present-meta">
                            {row.department ? <span>{row.department}</span> : null}
                            <span>
                              In {formatTimeHm(row.time_in)}
                              {row.time_out ? ` · Out ${formatTimeHm(row.time_out)}` : ' · Still in'}
                            </span>
                          </div>
                        </div>
                        <div className="att-present-side">
                          <span className={`att-present-status ${stillIn ? 'is-in' : 'is-out'}`}>
                            {stillIn ? 'In' : (row.status || 'Out')}
                          </span>
                          <span className="att-present-hours">
                            {row.total_hours != null
                              ? `${Number(row.total_hours).toFixed(1)}h`
                              : stillIn
                                ? formatElapsedCompact(row.time_in, null, hour, minute, second)
                                : '—'}
                          </span>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </section>

            <section className="att-leaderboard-card" aria-label="Team hours leaderboard">
              <div className="att-leaderboard-header">
                <h3 className="att-leaderboard-title">Leaderboard</h3>
                <span className="att-leaderboard-subtitle">Hours today</span>
              </div>
              {teamLeaderboard.length === 0 ? (
                <div className="att-desk-empty att-leaderboard-empty">
                  <div className="att-desk-empty-icon" aria-hidden="true">
                    <i className="fas fa-user-clock" />
                  </div>
                  <p className="att-desk-empty-title">No teammates found</p>
                  <p className="att-desk-empty-sub">
                    Company users will rank here against the {dailyTargetHours.toFixed(1)}h shift target.
                  </p>
                </div>
              ) : (
                <div className="att-leaderboard-list">
                  {teamLeaderboard.map((row, index) => (
                    <div
                      key={row.key}
                      className={[
                        'att-leaderboard-item',
                        row.hitTarget ? 'is-target-hit' : '',
                        row.isMe ? 'is-me' : '',
                        !row.clockedIn ? 'is-standby' : '',
                      ].filter(Boolean).join(' ')}
                    >
                      {row.hitTarget ? (
                        <>
                          <span className="att-leaderboard-spark att-leaderboard-spark--1" aria-hidden="true" />
                          <span className="att-leaderboard-spark att-leaderboard-spark--2" aria-hidden="true" />
                          <span className="att-leaderboard-spark att-leaderboard-spark--3" aria-hidden="true" />
                        </>
                      ) : null}
                      <span className="att-leaderboard-rank">{index + 1}</span>
                      <div className={`att-leaderboard-avatar${row.hitTarget ? ' is-target-hit' : ''}`}>
                        {row.initial}
                      </div>
                      <div className="att-leaderboard-info">
                        <div className="att-leaderboard-name-row">
                          <div className="att-leaderboard-name">
                            {row.name}
                            {row.isMe ? <span className="att-leaderboard-you">You</span> : null}
                          </div>
                          {row.hitTarget ? (
                            <span className="att-leaderboard-congrats">
                              <i className="fas fa-trophy" aria-hidden="true" />
                              Target reached!
                            </span>
                          ) : null}
                        </div>
                        <div className="att-leaderboard-progress-wrap">
                          <div className="att-leaderboard-progress-bar" aria-hidden="true">
                            <span
                              className={`att-leaderboard-progress-fill${row.hitTarget ? ' is-complete' : ''}`}
                              style={{ width: `${row.progress}%` }}
                            />
                          </div>
                          <div className="att-leaderboard-progress-text">
                            <span>{row.progress}% of {dailyTargetHours.toFixed(1)}h</span>
                            <span className="att-leaderboard-meta">
                              {!row.clockedIn
                                ? '· Standby'
                                : row.stillIn
                                  ? `· In ${formatTimeHm(row.timeIn)}`
                                  : `· Out ${formatTimeHm(row.timeOut || row.timeIn)}`}
                            </span>
                          </div>
                        </div>
                      </div>
                      <div className="att-leaderboard-hours">{row.hours.toFixed(1)}h</div>
                    </div>
                  ))}
                </div>
              )}
            </section>
            </div>
          </div>
        </div>
      </div>

      {planOpen ? (
        <PlanModal
          pendingTasks={state.pendingTasks}
          newTasks={newTasks}
          setNewTasks={setNewTasks}
          onClose={() => setPlanOpen(false)}
          onConfirm={submitClockIn}
          busy={busy}
        />
      ) : null}

      {reviewOpen ? (
        <ReviewModal
          pendingTasks={state.pendingTasks}
          completedIds={completedIds}
          setCompletedIds={setCompletedIds}
          onClose={() => setReviewOpen(false)}
          onConfirm={submitClockOut}
          busy={busy}
        />
      ) : null}
    </div>
  );
}

function ClockInSuccessOverlay({ data, onDismiss }) {
  const ciNew = Number(data.new_tasks || 0);
  const ciCarried = Number(data.carried_over || 0);
  const ciTotal = ciNew + ciCarried;
  return (
    <div
      className="att-clockin-success-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="clockInSuccessTitle"
      onClick={(e) => {
        if (e.target === e.currentTarget) onDismiss();
      }}
    >
      <div className="att-clockin-success-banner" role="status" aria-live="polite" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="att-clockin-success-dismiss" onClick={onDismiss} aria-label="Dismiss">
          <i className="fas fa-times" aria-hidden="true" />
        </button>
        <div className="att-clockin-success-head">
          <div className="att-clockin-success-icon" aria-hidden="true">
            <i className="fas fa-check" />
          </div>
          <div>
            <h2 id="clockInSuccessTitle" className="att-clockin-success-title">
              Clocked in successfully
            </h2>
            <p className="att-clockin-success-sub">
              You checked in at <strong>{data.time_in_display || formatDisplayTime(data.time_in)}</strong>
              {' - '}Status: <strong>{data.status || 'On Time'}</strong>
            </p>
          </div>
        </div>
        <div className="att-clockin-success-stats">
          <div className="att-clockin-success-stat">
            <strong>{ciNew}</strong> new task{ciNew === 1 ? '' : 's'} added
          </div>
          {ciCarried > 0 ? (
            <div className="att-clockin-success-stat">
              <strong>{ciCarried}</strong> carried over
            </div>
          ) : null}
          <div className="att-clockin-success-stat">
            <strong>{ciTotal}</strong> total for today
          </div>
        </div>
        {ciNew > 0 && Array.isArray(data.tasks) && data.tasks.length > 0 ? (
          <>
            <p className="att-success-note att-mb-1">Your new tasks</p>
            <ul className="att-clockin-success-tasks">
              {data.tasks.map((title, idx) => (
                <li key={`${title}-${idx}`}>
                  <span>{idx + 1}.</span>
                  <span>{title}</span>
                </li>
              ))}
            </ul>
          </>
        ) : null}
        {ciNew === 0 && ciCarried > 0 ? (
          <p className="att-success-body-note att-mb-0">
            No new tasks added - continuing with {ciCarried} carried-over task{ciCarried === 1 ? '' : 's'}.
          </p>
        ) : null}
      </div>
    </div>
  );
}

function ClockOutSuccessOverlay({ data, onDismiss }) {
  const coCompleted = Number(data.completed || 0);
  const coCarried = Number(data.carried_over || 0);
  const coTotal = coCompleted + coCarried;
  const coOvertime = Number(data.overtime_hours || 0);
  return (
    <div
      className="att-clockin-success-overlay att-clockout-success-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="clockOutSuccessTitle"
      onClick={(e) => {
        if (e.target === e.currentTarget) onDismiss();
      }}
    >
      <div
        className="att-clockin-success-banner att-clockout-success-banner"
        role="status"
        aria-live="polite"
        onClick={(e) => e.stopPropagation()}
      >
        <button type="button" className="att-clockin-success-dismiss" onClick={onDismiss} aria-label="Dismiss">
          <i className="fas fa-times" aria-hidden="true" />
        </button>
        <div className="att-clockin-success-head">
          <div className="att-clockin-success-icon" aria-hidden="true">
            <i className="fas fa-sign-out-alt" />
          </div>
          <div>
            <h2 id="clockOutSuccessTitle" className="att-clockin-success-title">
              Clocked out successfully
            </h2>
            <p className="att-clockin-success-sub">
              You checked out at <strong>{data.time_out_display}</strong>
              {data.time_in_display ? (
                <>
                  {' '}
                  - In at <strong>{data.time_in_display}</strong>
                </>
              ) : null}
              {' - '}
              <strong>{Number(data.total_hours || 0).toFixed(1)}h</strong> worked
            </p>
          </div>
        </div>
        <div className="att-clockin-success-stats">
          <div className="att-clockin-success-stat">
            <strong>{coCompleted}</strong> task{coCompleted === 1 ? '' : 's'} completed
          </div>
          {coCarried > 0 ? (
            <div className="att-clockin-success-stat">
              <strong>{coCarried}</strong> carrying over
            </div>
          ) : null}
          {coOvertime > 0 ? (
            <div className="att-clockin-success-stat">
              <strong>{coOvertime.toFixed(1)}h</strong> overtime
            </div>
          ) : coCarried === 0 ? (
            <div className="att-clockin-success-stat">
              <strong>{coTotal}</strong> tasks reviewed
            </div>
          ) : null}
        </div>
        {coCompleted > 0 && Array.isArray(data.completed_tasks) && data.completed_tasks.length > 0 ? (
          <>
            <p className="att-success-note att-mb-1">Completed today</p>
            <ul className="att-clockin-success-tasks">
              {data.completed_tasks.map((title, idx) => (
                <li key={`c-${title}-${idx}`}>
                  <span>{idx + 1}.</span>
                  <span>{title}</span>
                </li>
              ))}
            </ul>
          </>
        ) : null}
        {coCarried > 0 && Array.isArray(data.carried_tasks) && data.carried_tasks.length > 0 ? (
          <>
            <p className="att-success-note att-mb-1 att-mt-2">Carrying over tomorrow</p>
            <ul className="att-clockin-success-tasks">
              {data.carried_tasks.map((title, idx) => (
                <li key={`o-${title}-${idx}`}>
                  <span>{idx + 1}.</span>
                  <span>{title}</span>
                </li>
              ))}
            </ul>
          </>
        ) : null}
        {coTotal === 0 ? (
          <p className="att-success-body-note att-mb-0">No pending tasks for today. Have a great evening!</p>
        ) : null}
      </div>
    </div>
  );
}

function PlanModal({ pendingTasks, newTasks, setNewTasks, onClose, onConfirm, busy }) {
  return (
    <div
      className="att-plan-overlay is-open"
      aria-hidden="false"
      role="dialog"
      aria-labelledby="clockInModalTitle"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="att-plan-modal" role="document" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="att-plan-close" onClick={onClose} aria-label="Close">
          <i className="fas fa-times" aria-hidden="true" />
        </button>
        <div className="att-plan-header">
          <div className="att-plan-header-icon" aria-hidden="true">
            <i className="fas fa-list-ul" />
          </div>
          <h2 id="clockInModalTitle" className="att-plan-title">
            Plan Your Day
          </h2>
        </div>
        <div className="att-plan-body">
          {pendingTasks.length > 0 ? (
            <div className="att-plan-carried">
              <p className="att-plan-carried-label">Carried over tasks</p>
              <ul className="att-plan-carried-list">
                {pendingTasks.map((task) => (
                  <li key={task.id}>
                    <i className="fas fa-arrow-right" aria-hidden="true" />
                    <span>
                      {task.task_description}
                      <small>Added: {task.task_date}</small>
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          <p className="att-plan-label">New tasks for today</p>
          <div className="att-plan-tasks">
            {newTasks.map((value, index) => (
              <div className="att-plan-task-row" key={`task-${index}`}>
                <span className="att-plan-task-num" aria-hidden="true">
                  {index + 1}
                </span>
                <input
                  type="text"
                  className="new-task-input"
                  placeholder="e.g. Prepare monthly report..."
                  value={value}
                  onChange={(e) => {
                    const next = [...newTasks];
                    next[index] = e.target.value;
                    setNewTasks(next);
                  }}
                />
              </div>
            ))}
          </div>
          <button
            type="button"
            className="att-plan-add-task"
            onClick={() => setNewTasks((cur) => [...cur, ''])}
          >
            <span className="att-plan-add-icon" aria-hidden="true">
              <i className="fas fa-plus" />
            </span>
            Add another task
          </button>
        </div>
        <div className="att-plan-footer">
          <button type="button" className="att-plan-btn-cancel" onClick={onClose}>
            Cancel
          </button>
          <button type="button" className="att-plan-btn-confirm" disabled={busy} onClick={onConfirm}>
            <i className="fas fa-check" aria-hidden="true" /> Confirm &amp; Clock In
          </button>
        </div>
      </div>
    </div>
  );
}

function ReviewModal({ pendingTasks, completedIds, setCompletedIds, onClose, onConfirm, busy }) {
  return (
    <div
      className="att-review-overlay is-open"
      aria-hidden="false"
      role="dialog"
      aria-labelledby="clockOutModalTitle"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="att-review-modal" role="document" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="att-review-close" onClick={onClose} aria-label="Close">
          <i className="fas fa-times" aria-hidden="true" />
        </button>
        <div className="att-review-header">
          <div className="att-review-header-icon" aria-hidden="true">
            <i className="fas fa-check" />
          </div>
          <h2 id="clockOutModalTitle" className="att-review-title">
            Review Your Day
          </h2>
        </div>
        <p className="att-review-desc">
          Check off the tasks you completed today. Unchecked tasks will automatically carry over to tomorrow.
        </p>
        <div className="att-review-body">
          {pendingTasks.length > 0 ? (
            <div className="att-review-tasks">
              {pendingTasks.map((task) => {
                const id = Number(task.id);
                const checked = completedIds.has(id);
                return (
                  <label className="att-review-task" key={id}>
                    <input
                      type="checkbox"
                      className="completed-task-checkbox"
                      checked={checked}
                      onChange={(e) => {
                        setCompletedIds((cur) => {
                          const next = new Set(cur);
                          if (e.target.checked) next.add(id);
                          else next.delete(id);
                          return next;
                        });
                      }}
                      value={id}
                    />
                    <span>{task.task_description}</span>
                  </label>
                );
              })}
            </div>
          ) : (
            <div className="att-review-empty">
              <div className="att-review-empty-art" aria-hidden="true">
                <span className="att-review-empty-spark att-review-empty-spark--1">+</span>
                <span className="att-review-empty-spark att-review-empty-spark--2">+</span>
                <span className="att-review-empty-dot att-review-empty-dot--1" />
                <span className="att-review-empty-dot att-review-empty-dot--2" />
                <div className="att-review-empty-circle">
                  <div className="att-review-empty-list">
                    <div className="att-review-empty-row">
                      <span className="att-review-empty-mark done">
                        <i className="fas fa-check" />
                      </span>
                      <span className="att-review-empty-line" />
                    </div>
                    <div className="att-review-empty-row">
                      <span className="att-review-empty-mark done">
                        <i className="fas fa-check" />
                      </span>
                      <span className="att-review-empty-line" />
                    </div>
                    <div className="att-review-empty-row">
                      <span className="att-review-empty-mark pending" />
                      <span className="att-review-empty-line" />
                    </div>
                  </div>
                </div>
              </div>
              <p className="att-review-empty-title">No pending tasks for today.</p>
              <p className="att-review-empty-sub">Great work! Enjoy the rest of your day.</p>
            </div>
          )}
        </div>
        <div className="att-review-footer">
          <button type="button" className="att-review-btn-cancel" onClick={onClose}>
            Cancel
          </button>
          <button type="button" className="att-review-btn-confirm" disabled={busy} onClick={onConfirm}>
            <i className="far fa-clock" aria-hidden="true" /> Confirm &amp; Clock Out
          </button>
        </div>
      </div>
    </div>
  );
}
