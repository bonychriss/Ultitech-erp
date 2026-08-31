export type ProfileAvatarVariant = 'supplier' | 'payer';

const SUPPLIER_COLORS = [
  '#7c3aed',
  '#2563eb',
  '#059669',
  '#d97706',
  '#db2777',
  '#0891b2',
  '#4f46e5',
  '#ea580c',
  '#16a34a',
  '#9333ea',
  '#0d9488',
  '#e11d48',
  '#b45309',
  '#1d4ed8',
  '#be185d',
];

const PAYER_COLORS = [
  '#0f766e',
  '#4338ca',
  '#b91c1c',
  '#7c2d12',
  '#5b21b6',
  '#0369a1',
  '#15803d',
  '#a21caf',
  '#c2410c',
  '#1e40af',
  '#047857',
  '#9d174d',
  '#334155',
  '#0e7490',
  '#4d7c0f',
];

export function profileInitials(name: string): string {
  const trimmed = name.trim();
  if (!trimmed) return '?';
  const parts = trimmed.split(/\s+/).filter(Boolean);
  if (parts.length >= 2) {
    return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase();
  }
  return trimmed.slice(0, 2).toUpperCase();
}

export function profileAvatarColor(name: string, variant: ProfileAvatarVariant = 'supplier'): string {
  const palette = variant === 'payer' ? PAYER_COLORS : SUPPLIER_COLORS;
  const key = `${variant}:${name.trim().toLowerCase()}`;
  let hash = 0;

  for (let i = 0; i < key.length; i += 1) {
    hash = (Math.imul(31, hash) + key.charCodeAt(i)) | 0;
  }

  const index = Math.abs(hash) % palette.length;
  return palette[index] ?? palette[0];
}

interface ProfileAvatarProps {
  name: string;
  variant?: ProfileAvatarVariant;
  className?: string;
}

export default function ProfileAvatar({
  name,
  variant = 'supplier',
  className = '',
}: ProfileAvatarProps) {
  const displayName = name.trim() || (variant === 'payer' ? 'User' : 'Supplier');
  const initials = profileInitials(displayName);
  const background = profileAvatarColor(displayName, variant);

  return (
    <span
      className={`sppd-profile-avatar sppd-profile-avatar--${variant}${className ? ` ${className}` : ''}`}
      style={{ backgroundColor: background }}
      title={displayName}
      aria-hidden="true"
    >
      {initials}
    </span>
  );
}
