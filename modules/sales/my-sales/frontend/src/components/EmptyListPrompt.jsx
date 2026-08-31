import { Plus } from 'lucide-react';

export default function EmptyListPrompt({
  icon,
  title,
  message,
  actionLabel,
  actionHref,
  variant = 'orders',
}) {
  if (!actionHref) {
    return (
      <div className={`ms-empty-prompt ms-empty-prompt--${variant}`}>
        <div className="ms-empty-prompt-icon-wrap" aria-hidden="true">
          <div className="ms-empty-prompt-icon">{icon}</div>
          <span className="ms-empty-prompt-ring" />
          <span className="ms-empty-prompt-ring ms-empty-prompt-ring--delayed" />
        </div>
        <p className="ms-empty-prompt-title">{title}</p>
        <p className="ms-empty-prompt-message">{message}</p>
      </div>
    );
  }

  return (
    <div className={`ms-empty-prompt ms-empty-prompt--${variant}`}>
      <div className="ms-empty-prompt-icon-wrap" aria-hidden="true">
        <div className="ms-empty-prompt-icon">{icon}</div>
        <span className="ms-empty-prompt-ring" />
        <span className="ms-empty-prompt-ring ms-empty-prompt-ring--delayed" />
      </div>
      <p className="ms-empty-prompt-title">{title}</p>
      <p className="ms-empty-prompt-message">{message}</p>
      <a href={actionHref} className="ms-btn ms-btn--purple ms-empty-prompt-cta">
        <Plus size={14} />
        {actionLabel}
      </a>
    </div>
  );
}
