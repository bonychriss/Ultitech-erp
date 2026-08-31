import { avatarStyle, employeeInitials } from '../utils/avatar';

export default function EmployeeAvatar({ name, id, large = false, className = '' }) {
  const label = employeeInitials(name);
  const cls = `pay-desk-avatar${large ? ' pay-desk-avatar--lg' : ''}${className ? ` ${className}` : ''}`;

  return (
    <div className={cls} style={avatarStyle(name, id)} aria-hidden="true">
      {label}
    </div>
  );
}
