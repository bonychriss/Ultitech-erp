import type { AttendanceEntry, EmployeeProfile, NotificationItem, TimeSettings } from '../types/attendance';
import { getLocalTimezoneLabel } from '../utils/timeUtils';

export const EMPLOYEE_PROFILES: EmployeeProfile[] = [
  {
    id: 'emp-admin',
    name: 'System Admin',
    role: 'Admin',
    department: 'Operations',
    shiftWindow: '09:00 – 17:30',
    initials: 'SA',
  },
  {
    id: 'emp-amira',
    name: 'Amira Hassan',
    role: 'Senior Trader',
    department: 'Commodities',
    shiftWindow: '08:30 – 17:00',
    initials: 'AH',
  },
  {
    id: 'emp-marcus',
    name: 'Marcus Chen',
    role: 'Logistics Lead',
    department: 'Supply Chain',
    shiftWindow: '09:00 – 17:30',
    initials: 'MC',
  },
];

export const DEFAULT_TIME_SETTINGS: TimeSettings = {
  use24Hour: false,
  showSeconds: true,
  dailyTargetHours: 8,
  timezoneLabel: getLocalTimezoneLabel(),
};

export function createSampleTeamEntries(nowMs: number): AttendanceEntry[] {
  const todayLabel = new Date(nowMs).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
  const eightThirty = new Date(nowMs);
  eightThirty.setHours(8, 32, 0, 0);

  const nineOhFive = new Date(nowMs);
  nineOhFive.setHours(9, 5, 0, 0);

  const nineEighteen = new Date(nowMs);
  nineEighteen.setHours(9, 18, 0, 0);

  return [
    {
      id: 'sample-amira',
      employeeId: 'emp-amira',
      employeeName: 'Amira Hassan',
      role: 'Senior Trader',
      department: 'Commodities',
      dateLabel: todayLabel,
      status: 'Clocked In',
      clockInMs: Math.min(eightThirty.getTime(), nowMs - 2.4 * 3600 * 1000),
      clockOutMs: null,
      breakStartMs: null,
      breakTotalMs: 0,
    },
    {
      id: 'sample-marcus',
      employeeId: 'emp-marcus',
      employeeName: 'Marcus Chen',
      role: 'Logistics Lead',
      department: 'Supply Chain',
      dateLabel: todayLabel,
      status: 'On Break',
      clockInMs: Math.min(nineOhFive.getTime(), nowMs - 1.8 * 3600 * 1000),
      clockOutMs: null,
      breakStartMs: nowMs - 12 * 60 * 1000,
      breakTotalMs: 0,
    },
    {
      id: 'sample-nadia',
      employeeId: 'emp-nadia',
      employeeName: 'Nadia Al-Mansoor',
      role: 'Compliance Officer',
      department: 'Risk & Audit',
      dateLabel: todayLabel,
      status: 'Clocked In',
      clockInMs: Math.min(nineEighteen.getTime(), nowMs - 1.5 * 3600 * 1000),
      clockOutMs: null,
      breakStartMs: null,
      breakTotalMs: 0,
    },
  ];
}

export const INITIAL_RECORDS: AttendanceEntry[] = [
  {
    id: 'rec-101',
    employeeId: 'emp-admin',
    employeeName: 'System Admin',
    role: 'Admin',
    department: 'Operations',
    dateLabel: 'Nov 24, 2026',
    status: 'Clocked Out',
    clockInMs: new Date(2026, 10, 24, 8, 56, 0).getTime(),
    clockOutMs: new Date(2026, 10, 24, 17, 42, 0).getTime(),
    breakStartMs: null,
    breakTotalMs: 30 * 60 * 1000,
  },
  {
    id: 'rec-102',
    employeeId: 'emp-amira',
    employeeName: 'Amira Hassan',
    role: 'Senior Trader',
    department: 'Commodities',
    dateLabel: 'Nov 24, 2026',
    status: 'Clocked Out',
    clockInMs: new Date(2026, 10, 24, 8, 28, 0).getTime(),
    clockOutMs: new Date(2026, 10, 24, 18, 10, 0).getTime(),
    breakStartMs: null,
    breakTotalMs: 45 * 60 * 1000,
  },
  {
    id: 'rec-103',
    employeeId: 'emp-marcus',
    employeeName: 'Marcus Chen',
    role: 'Logistics Lead',
    department: 'Supply Chain',
    dateLabel: 'Nov 24, 2026',
    status: 'Clocked Out',
    clockInMs: new Date(2026, 10, 24, 9, 4, 0).getTime(),
    clockOutMs: new Date(2026, 10, 24, 17, 15, 0).getTime(),
    breakStartMs: null,
    breakTotalMs: 30 * 60 * 1000,
  },
  {
    id: 'rec-104',
    employeeId: 'emp-admin',
    employeeName: 'System Admin',
    role: 'Admin',
    department: 'Operations',
    dateLabel: 'Nov 23, 2026',
    status: 'Clocked Out',
    clockInMs: new Date(2026, 10, 23, 9, 1, 0).getTime(),
    clockOutMs: new Date(2026, 10, 23, 17, 35, 0).getTime(),
    breakStartMs: null,
    breakTotalMs: 30 * 60 * 1000,
  },
];

export const INITIAL_NOTIFICATIONS: NotificationItem[] = [
  {
    id: 'notif-1',
    title: 'Shift Window Active',
    detail: 'Morning operations window opened at 09:00 AM. Clock in when ready to begin tracking.',
    timeLabel: 'Just now',
    read: false,
  },
  {
    id: 'notif-2',
    title: 'Yesterday Timesheet Closed',
    detail: 'Nov 24 attendance summary (8.3h logged) has been recorded in View Records.',
    timeLabel: 'Yesterday',
    read: false,
  },
];
