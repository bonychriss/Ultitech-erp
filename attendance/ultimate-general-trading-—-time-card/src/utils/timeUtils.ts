import type { AttendanceEntry } from '../types/attendance';

export interface FormattedTimeParts {
  hours: string;
  minutes: string;
  seconds: string;
  period: 'AM' | 'PM' | null;
  full: string;
}

export function formatTimeParts(date: Date, use24Hour: boolean): FormattedTimeParts {
  const rawHours = date.getHours();
  const minutes = String(date.getMinutes()).padStart(2, '0');
  const seconds = String(date.getSeconds()).padStart(2, '0');

  if (use24Hour) {
    const hours = String(rawHours).padStart(2, '0');
    return {
      hours,
      minutes,
      seconds,
      period: null,
      full: `${hours}:${minutes}:${seconds}`,
    };
  }

  const period: 'AM' | 'PM' = rawHours >= 12 ? 'PM' : 'AM';
  const displayHour = rawHours % 12 === 0 ? 12 : rawHours % 12;
  const hours = String(displayHour).padStart(2, '0');

  return {
    hours,
    minutes,
    seconds,
    period,
    full: `${hours}:${minutes}:${seconds} ${period}`,
  };
}

export function formatFullDate(date: Date): string {
  return date.toLocaleDateString('en-US', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  });
}

export function getTimeGreeting(hour: number): string {
  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';
  return 'Good evening';
}

export interface WorkedHoursBreakdown {
  totalHours: number;
  regularHours: number;
  overtimeHours: number;
  progressPercent: number;
}

export function calculateWorkedHours(
  clockInMs: number | null,
  clockOutMs: number | null,
  nowMs: number,
  breakTotalMs = 0,
  activeBreakStartMs: number | null = null,
  dailyTargetHours = 8
): WorkedHoursBreakdown {
  if (clockInMs === null) {
    return {
      totalHours: 0,
      regularHours: 0,
      overtimeHours: 0,
      progressPercent: 0,
    };
  }

  const endMs = clockOutMs ?? nowMs;
  const currentBreakMs =
    activeBreakStartMs !== null && clockOutMs === null
      ? Math.max(0, nowMs - activeBreakStartMs)
      : 0;
  const netMs = Math.max(0, endMs - clockInMs - breakTotalMs - currentBreakMs);
  const totalHours = netMs / (1000 * 60 * 60);
  const safeTarget = Math.max(1, dailyTargetHours);
  const regularHours = Math.min(totalHours, safeTarget);
  const overtimeHours = Math.max(0, totalHours - safeTarget);
  const progressPercent = Math.min(100, Math.round((totalHours / safeTarget) * 100));

  return {
    totalHours,
    regularHours,
    overtimeHours,
    progressPercent,
  };
}

export function formatHoursDecimal(hours: number): string {
  return `${Math.max(0, hours).toFixed(1)}h`;
}

export function formatDurationCompact(
  clockInMs: number | null,
  clockOutMs: number | null,
  nowMs: number,
  breakTotalMs = 0,
  activeBreakStartMs: number | null = null
): string {
  if (clockInMs === null) return '0h 00m';
  const endMs = clockOutMs ?? nowMs;
  const currentBreakMs =
    activeBreakStartMs !== null && clockOutMs === null
      ? Math.max(0, nowMs - activeBreakStartMs)
      : 0;
  const netMs = Math.max(0, endMs - clockInMs - breakTotalMs - currentBreakMs);
  const totalMinutes = Math.floor(netMs / 60000);
  const hrs = Math.floor(totalMinutes / 60);
  const mins = String(totalMinutes % 60).padStart(2, '0');
  return `${hrs}h ${mins}m`;
}

export function formatShortTimestamp(ms: number | null, use24Hour: boolean): string {
  if (ms === null) return '—';
  const date = new Date(ms);
  const rawHours = date.getHours();
  const minutes = String(date.getMinutes()).padStart(2, '0');
  if (use24Hour) {
    return `${String(rawHours).padStart(2, '0')}:${minutes}`;
  }
  const period = rawHours >= 12 ? 'PM' : 'AM';
  const displayHour = rawHours % 12 === 0 ? 12 : rawHours % 12;
  return `${displayHour}:${minutes} ${period}`;
}

export function filterAttendanceRecords(
  records: AttendanceEntry[],
  query: string,
  statusFilter: 'All' | 'Clocked In' | 'On Break' | 'Clocked Out' | 'Overtime',
  nowMs: number,
  dailyTargetHours = 8
): AttendanceEntry[] {
  const normalized = query.trim().toLowerCase();

  return records.filter((entry) => {
    const matchesQuery =
      normalized.length === 0 ||
      entry.employeeName.toLowerCase().includes(normalized) ||
      entry.department.toLowerCase().includes(normalized) ||
      entry.role.toLowerCase().includes(normalized) ||
      entry.dateLabel.toLowerCase().includes(normalized);

    if (!matchesQuery) return false;

    if (statusFilter === 'All') return true;
    if (statusFilter === 'Overtime') {
      const breakdown = calculateWorkedHours(
        entry.clockInMs,
        entry.clockOutMs,
        nowMs,
        entry.breakTotalMs,
        entry.breakStartMs,
        dailyTargetHours
      );
      return breakdown.overtimeHours > 0;
    }

    return entry.status === statusFilter;
  });
}

export function getLocalTimezoneLabel(): string {
  try {
    const tzName = Intl.DateTimeFormat(undefined, { timeZoneName: 'short' })
      .formatToParts(new Date())
      .find((part) => part.type === 'timeZoneName')?.value;
    return tzName || 'Local Time';
  } catch {
    return 'Local Time';
  }
}
