export type ShiftStatus = 'Standby' | 'Clocked In' | 'On Break' | 'Clocked Out';

export type NavView = 'overview' | 'clock' | 'records' | 'settings';

export interface EmployeeProfile {
  id: string;
  name: string;
  role: string;
  department: string;
  shiftWindow: string;
  initials: string;
}

export interface AttendanceEntry {
  id: string;
  employeeId: string;
  employeeName: string;
  role: string;
  department: string;
  dateLabel: string;
  status: ShiftStatus;
  clockInMs: number | null;
  clockOutMs: number | null;
  breakStartMs: number | null;
  breakTotalMs: number;
}

export interface TimeSettings {
  use24Hour: boolean;
  showSeconds: boolean;
  dailyTargetHours: number;
  timezoneLabel: string;
}

export interface NotificationItem {
  id: string;
  title: string;
  detail: string;
  timeLabel: string;
  read: boolean;
}
