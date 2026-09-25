import muhimbiliLogo from '../assets/muhimbili_logo.png';

/**
 * The single blue ribbon brand header (title with the subtitle underneath), shared by the login
 * page, the role sidebar, and every dashboard page so the same identity
 * chrome appears consistently across the app.
 */
export default function BrandHeader({
  title = 'MUHIMBILI NATIONAL HOSPITAL SYSTEM',
  subtitle = 'Dynamic Queue System',
  right = null,
  compact = false,
}) {
  return (
    <header className={`brand-header${compact ? ' brand-header--compact' : ''}`}>
      <div className="brand-header__main">
        <div className="brand-lockup">
          <img
            src={muhimbiliLogo}
            onError={(event) => { event.currentTarget.src = '/muhimbili-logo.svg'; }}
            alt="Muhimbili National Hospital"
          />
          {(title || subtitle) && (
            <div className="brand-lockup__text">
              {title && <span>{title}</span>}
              {subtitle && <small>{subtitle}</small>}
            </div>
          )}
        </div>
        {right}
      </div>
    </header>
  );
}
