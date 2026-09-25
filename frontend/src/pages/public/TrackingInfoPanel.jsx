import { CloseOutlined, HomeOutlined, PhoneOutlined, UnorderedListOutlined } from '@ant-design/icons';
import BrandHeader from '../../components/BrandHeader';

/**
 * Slide-in sidebar for the public tracking page — same toggle/drawer
 * pattern as staff dashboards' RoleSidebar (identical .role-sidebar*
 * CSS). Holds the Home / Status navigation, the English/Kiswahili
 * switch, and the hospital's support number. The notification history
 * and "what happens next" now live on the pages it navigates to (see
 * TrackingPage), not in here.
 */
export default function TrackingInfoPanel({ open, onClose, view, onNavigate, language, onLanguageChange, t }) {
  const navigate = (next) => {
    onNavigate(next);
    onClose();
  };

  return (
    <>
      <div className={`role-sidebar__overlay${open ? ' is-open' : ''}`} onClick={onClose} aria-hidden="true" />
      <aside className={`role-sidebar${open ? ' is-open' : ''}`} aria-hidden={!open}>
        <button type="button" className="role-sidebar__close" aria-label={t.closeMenu} onClick={onClose}><CloseOutlined /></button>
        <BrandHeader compact subtitle={t.yourVisit} />

        <nav className="role-sidebar__nav" style={{ flex: 'none' }}>
          <button type="button" className={view === 'home' ? 'is-active' : ''} onClick={() => navigate('home')}>
            <HomeOutlined /> {t.home}
          </button>
          <button type="button" className={view === 'status' ? 'is-active' : ''} onClick={() => navigate('status')}>
            <UnorderedListOutlined /> {t.status}
          </button>
        </nav>

        <div className="tracking-info-panel__section" style={{ flex: 1 }}>
          <h4>{t.language}</h4>
          <div className="tracking-lang-switch" role="group" aria-label={t.language}>
            <button type="button" className={language === 'en' ? 'is-active' : ''} onClick={() => onLanguageChange('en')}>English</button>
            <button type="button" className={language === 'sw' ? 'is-active' : ''} onClick={() => onLanguageChange('sw')}>Kiswahili</button>
          </div>
        </div>

        <div className="tracking-info-panel__section">
          <h4>{t.needHelp}</h4>
          <p style={{ margin: 0 }}><PhoneOutlined /> {t.support}: +255 22 215 1367</p>
        </div>
      </aside>
    </>
  );
}
