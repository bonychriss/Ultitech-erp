interface FilterSlidersIconProps {
  className?: string;
}

export default function FilterSlidersIcon({ className = '' }: FilterSlidersIconProps) {
  return (
    <svg
      className={className}
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <line x1="4" y1="6" x2="20" y2="6" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
      <circle cx="15" cy="6" r="2.2" fill="#fff" stroke="currentColor" strokeWidth="2.2" />
      <line x1="4" y1="12" x2="20" y2="12" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
      <circle cx="9" cy="12" r="2.2" fill="#fff" stroke="currentColor" strokeWidth="2.2" />
      <line x1="4" y1="18" x2="20" y2="18" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
      <circle cx="13" cy="18" r="2.2" fill="#fff" stroke="currentColor" strokeWidth="2.2" />
    </svg>
  );
}
