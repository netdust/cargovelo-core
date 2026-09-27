import { useEffect, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { IconButton } from '@sakaniui/react';

interface Props {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  subtitle?: ReactNode;
  leading?: ReactNode;
  width?: number;
  children: ReactNode;
  footer?: ReactNode;
}

/** Right-hand detail panel (Sakani has no Drawer). Escape closes; focus stays inside the page. */
export function Drawer({ open, onClose, title, subtitle, leading, width = 520, children, footer }: Props) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  return (
    <>
      <div className="cv-drawer-backdrop" onClick={onClose} aria-hidden="true" />
      <aside className="cv-drawer" style={{ width }} role="dialog" aria-modal="true" aria-label={typeof title === 'string' ? title : 'Detail'}>
        <header className="cv-drawer__head">
          {leading}
          <div className="cv-drawer__title">
            <div className="cv-drawer__h">{title}</div>
            {subtitle && <div className="cv-drawer__sub">{subtitle}</div>}
          </div>
          <IconButton icon={X} variant="ghost" size="sm" aria-label="Sluiten" onClick={onClose} />
        </header>
        <div className="cv-drawer__body">{children}</div>
        {footer && <footer className="cv-drawer__foot">{footer}</footer>}
      </aside>
    </>
  );
}
