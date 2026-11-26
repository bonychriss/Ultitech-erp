import { Bell, Check, X } from 'lucide-react';
import type { NotificationItem } from '../types/attendance';

interface NotificationsDrawerProps {
  open: boolean;
  notifications: NotificationItem[];
  onClose: () => void;
  onMarkAllRead: () => void;
}

export function NotificationsDrawer({
  open,
  notifications,
  onClose,
  onMarkAllRead,
}: NotificationsDrawerProps) {
  if (!open) return null;

  const unreadCount = notifications.filter((n) => !n.read).length;

  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      <button
        type="button"
        aria-label="Close notifications panel"
        onClick={onClose}
        className="fixed inset-0 bg-slate-950/40 backdrop-blur-xs"
      />
      <aside
        role="dialog"
        aria-label="Notifications"
        className="relative z-10 flex h-full w-full max-w-sm flex-col border-l border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900"
      >
        <div className="flex items-center justify-between border-b border-slate-200/80 px-5 py-4 dark:border-slate-800">
          <div className="flex items-center gap-2">
            <Bell className="h-4.5 w-4.5 text-slate-700 dark:text-slate-200" />
            <h2 className="text-sm font-semibold text-slate-900 dark:text-white">
              Notifications
            </h2>
            {unreadCount > 0 && (
              <span className="font-mono text-xs font-medium text-blue-600 dark:text-sky-400 tabular-nums">
                ({unreadCount})
              </span>
            )}
          </div>
          <div className="flex items-center gap-2">
            {unreadCount > 0 && (
              <button
                type="button"
                onClick={onMarkAllRead}
                className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-blue-600 hover:bg-blue-50 dark:text-sky-400 dark:hover:bg-blue-950/50"
              >
                <Check className="h-3.5 w-3.5" />
                <span>Mark read</span>
              </button>
            )}
            <button
              type="button"
              onClick={onClose}
              aria-label="Close notifications"
              className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>

        <div className="flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
          {notifications.map((item) => (
            <div
              key={item.id}
              className={`px-5 py-4 ${
                item.read ? 'opacity-70' : 'bg-blue-50/40 dark:bg-blue-950/20'
              }`}
            >
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-xs font-semibold text-slate-900 dark:text-white">
                  {item.title}
                </h3>
                <span className="text-[11px] text-slate-400 dark:text-slate-500">
                  {item.timeLabel}
                </span>
              </div>
              <p className="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                {item.detail}
              </p>
            </div>
          ))}
        </div>
      </aside>
    </div>
  );
}
