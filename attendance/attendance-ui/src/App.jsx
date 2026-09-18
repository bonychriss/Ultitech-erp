import React from 'react';
import AttendanceDesk from './pages/AttendanceDesk';
import AttendanceRecords from './pages/AttendanceRecords';
import AttendanceSettings from './pages/AttendanceSettings';
import AttendanceAnalytics from './pages/AttendanceAnalytics';

export default function App({ page = 'clock', data = {} }) {
  switch (page) {
    case 'records':
      return <AttendanceRecords data={data} />;
    case 'settings':
      return <AttendanceSettings data={data} />;
    case 'analytics':
      return <AttendanceAnalytics data={data} />;
    case 'clock':
    default:
      return <AttendanceDesk data={data} />;
  }
}
