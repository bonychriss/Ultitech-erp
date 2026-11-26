import { useState, useEffect } from 'react';

interface AvatarProps {
  src: string | null;
  firstName: string;
  lastName: string;
  size?: 'sm' | 'md' | 'lg';
}

export default function Avatar({ src, firstName, lastName, size = 'lg' }: AvatarProps) {
  const [hasError, setHasError] = useState(false);

  useEffect(() => {
    setHasError(false);
  }, [src]);

  const initials = `${firstName.trim().charAt(0) || ''}${lastName.trim().charAt(0) || ''}`.toUpperCase() || 'U';

  const sizeClasses = {
    sm: 'w-9 h-9 text-xs',
    md: 'w-11 h-11 text-sm',
    lg: 'w-20 h-20 text-xl',
  }[size];

  if (!src || hasError) {
    return (
      <div
        aria-label={`${firstName} ${lastName}`}
        className={`${sizeClasses} shrink-0 rounded-full bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-semibold flex items-center justify-center border border-slate-300 dark:border-slate-700 select-none`}
      >
        {initials}
      </div>
    );
  }

  return (
    <img
      src={src}
      alt={`${firstName} ${lastName}`}
      referrerPolicy="no-referrer"
      onError={() => setHasError(true)}
      className={`${sizeClasses} shrink-0 rounded-full object-cover border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800`}
    />
  );
}
