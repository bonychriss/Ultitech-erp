export function LoadingMail({ label = 'Loading Mail...' }: { label?: string }) {
  return (
    <div className="loading loading-mail" role="status" aria-live="polite" aria-busy="true">
      <div className="loading-mail-visual" aria-hidden="true">
        <div className="loading-mail-envelope">
          <span className="loading-mail-flap" />
          <span className="loading-mail-body" />
          <span className="loading-mail-shine" />
        </div>
        <div className="loading-mail-dots">
          <span />
          <span />
          <span />
        </div>
      </div>
      <p className="loading-mail-label">{label}</p>
    </div>
  );
}
