
import { AttendanceStatus } from "./types";

export const formatTime = (date: Date): string => {
  return date.toTimeString().slice(0, 5);
};

export const calculateStatus = (
  timeIn: string,
  startTime: string,
  graceMinutes: number
): AttendanceStatus => {
  const [inH, inM] = timeIn.split(':').map(Number);
  const [startH, startM] = startTime.split(':').map(Number);
  
  const inTotal = inH * 60 + inM;
  const startTotal = startH * 60 + startM;
  
  if (inTotal > startTotal + graceMinutes) return AttendanceStatus.LATE;
  if (inTotal < startTotal) return AttendanceStatus.EARLY;
  return AttendanceStatus.ON_TIME;
};

export const calculateOvertime = (timeOut: string, endTime: string): number => {
  const [outH, outM] = timeOut.split(':').map(Number);
  const [endH, endM] = endTime.split(':').map(Number);
  
  const outTotal = outH * 60 + outM;
  const endTotal = endH * 60 + endM;
  
  if (outTotal > endTotal) {
    return Number(((outTotal - endTotal) / 60).toFixed(2));
  }
  return 0;
};

export const calculateTotalHours = (timeIn: string, timeOut: string): number => {
  const [inH, inM] = timeIn.split(':').map(Number);
  const [outH, outM] = timeOut.split(':').map(Number);
  
  const inTotal = inH * 60 + inM;
  const outTotal = outH * 60 + outM;
  
  return Number(((outTotal - inTotal) / 60).toFixed(2));
};
