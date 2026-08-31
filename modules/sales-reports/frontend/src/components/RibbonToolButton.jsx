export default function RibbonToolButton({
  icon,
  label,
  title,
  onClick,
  disabled = false,
  className = '',
  ...props
}) {
  return (
    <button
      type="button"
      className={`word-ribbon-tool${className ? ` ${className}` : ''}`}
      title={title || label}
      aria-label={label}
      onClick={onClick}
      disabled={disabled}
      {...props}
    >
      <i className={`bi ${icon}`} aria-hidden="true" />
      <span>{label}</span>
    </button>
  )
}
