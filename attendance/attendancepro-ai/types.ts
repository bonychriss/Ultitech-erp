
export enum UserRole {
  ADMIN = 'admin',
  USER = 'user'
}

export enum AttendanceStatus {
  LATE = 'Late',
  ON_TIME = 'On Time',
  EARLY = 'Early'
}

export interface Employee {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  hourlyRate: number;
  avatar: string;
}

export interface AttendanceRecord {
  id: string;
  employeeId: string;
  date: string;
  timeIn: string;
  timeOut: string | null;
  status: AttendanceStatus;
  overtimeHours: number;
  totalHours: number;
}

export interface Settings {
  startTime: string; // "HH:mm"
  endTime: string; // "HH:mm"
  officeIpAddress: string;
  gracePeriodMinutes: number;
}

export interface AppState {
  currentUser: Employee | null;
  employees: Employee[];
  attendance: AttendanceRecord[];
  settings: Settings;
  currentIp: string;
}
