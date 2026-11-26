import test from 'node:test';
import assert from 'node:assert/strict';
import {
  calculateWorkedHours,
  filterAttendanceRecords,
  formatDurationCompact,
  formatFullDate,
  formatHoursDecimal,
  formatShortTimestamp,
  formatTimeParts,
  getTimeGreeting,
} from './timeUtils';
import type { AttendanceEntry } from '../types/attendance';

test('formatTimeParts formats 12-hour and 24-hour times accurately', () => {
  const morning = new Date(2026, 10, 25, 9, 5, 8);
  assert.deepEqual(formatTimeParts(morning, false), {
    hours: '09',
    minutes: '05',
    seconds: '08',
    period: 'AM',
    full: '09:05:08 AM',
  });
  assert.deepEqual(formatTimeParts(morning, true), {
    hours: '09',
    minutes: '05',
    seconds: '08',
    period: null,
    full: '09:05:08',
  });

  const midnight = new Date(2026, 10, 25, 0, 12, 0);
  assert.equal(formatTimeParts(midnight, false).hours, '12');
  assert.equal(formatTimeParts(midnight, false).period, 'AM');

  const afternoon = new Date(2026, 10, 25, 14, 30, 45);
  assert.equal(formatTimeParts(afternoon, false).full, '02:30:45 PM');
  assert.equal(formatTimeParts(afternoon, true).full, '14:30:45');
});

test('getTimeGreeting chooses appropriate time-of-day greeting', () => {
  assert.equal(getTimeGreeting(8), 'Good morning');
  assert.equal(getTimeGreeting(13), 'Good afternoon');
  assert.equal(getTimeGreeting(19), 'Good evening');
});

test('formatFullDate returns readable weekday, month, day, and year', () => {
  const sample = new Date(2026, 10, 25, 11, 7, 28);
  assert.equal(formatFullDate(sample), 'Wednesday, November 25, 2026');
});

test('calculateWorkedHours deducts breaks and calculates overtime past target', () => {
  const start = Date.UTC(2026, 10, 25, 8, 0, 0);
  const nineHalfHoursLater = start + 9.5 * 3600 * 1000;
  const thirtyMinBreak = 0.5 * 3600 * 1000;

  const breakdown = calculateWorkedHours(start, nineHalfHoursLater, nineHalfHoursLater, thirtyMinBreak, null, 8);
  assert.equal(breakdown.totalHours, 9);
  assert.equal(breakdown.regularHours, 8);
  assert.equal(breakdown.overtimeHours, 1);
  assert.equal(breakdown.progressPercent, 100);
  assert.equal(formatHoursDecimal(breakdown.totalHours), '9.0h');
});

test('calculateWorkedHours handles standby state cleanly', () => {
  const breakdown = calculateWorkedHours(null, null, Date.now());
  assert.equal(breakdown.totalHours, 0);
  assert.equal(breakdown.overtimeHours, 0);
  assert.equal(formatDurationCompact(null, null, Date.now()), '0h 00m');
});

test('formatShortTimestamp returns dash for null and formatted string for valid ms', () => {
  assert.equal(formatShortTimestamp(null, false), '—');
  const d = new Date(2026, 10, 25, 16, 4, 0).getTime();
  assert.equal(formatShortTimestamp(d, false), '4:04 PM');
  assert.equal(formatShortTimestamp(d, true), '16:04');
});

test('filterAttendanceRecords filters by query and status including Overtime', () => {
  const base = Date.UTC(2026, 10, 25, 8, 0, 0);
  const entries: AttendanceEntry[] = [
    {
      id: '1',
      employeeId: 'e1',
      employeeName: 'System Admin',
      role: 'Admin',
      department: 'Operations',
      dateLabel: 'Nov 25, 2026',
      status: 'Clocked Out',
      clockInMs: base,
      clockOutMs: base + 9 * 3600 * 1000,
      breakStartMs: null,
      breakTotalMs: 0,
    },
    {
      id: '2',
      employeeId: 'e2',
      employeeName: 'Amira Hassan',
      role: 'Trade Desk',
      department: 'Commodities',
      dateLabel: 'Nov 25, 2026',
      status: 'Clocked In',
      clockInMs: base,
      clockOutMs: null,
      breakStartMs: null,
      breakTotalMs: 0,
    },
  ];

  assert.equal(filterAttendanceRecords(entries, 'amira', 'All', base + 4 * 3600 * 1000).length, 1);
  assert.equal(filterAttendanceRecords(entries, '', 'Overtime', base + 4 * 3600 * 1000).length, 1);
  assert.equal(filterAttendanceRecords(entries, '', 'Clocked In', base + 4 * 3600 * 1000).length, 1);
});
