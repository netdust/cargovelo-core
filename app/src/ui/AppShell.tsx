import { useState, type ReactNode } from 'react';
import { Bike, LogOut, type LucideIcon } from 'lucide-react';
import { Avatar, Sidebar, SidebarDivider, SidebarFooter, SidebarGroupLabel, SidebarHeader, SidebarItem, TopBar, TopBarMobile } from '@sakaniui/react';
import { useAppContext } from '../lib/api-context';
import { initials } from '../lib/format';

export interface NavItem {
  key: string;
  label: string;
  icon: LucideIcon;
  badge?: string;
  group?: string;
}

interface Props {
  title: string;
  subtitle: string;
  nav: NavItem[];
  active: string;
  onNavigate: (key: string) => void;
  topLeft?: ReactNode;
  topRight?: ReactNode;
  side?: ReactNode;
  flush?: boolean;
  children: ReactNode;
}

export function AppShell({ title, subtitle, nav, active, onNavigate, topLeft, topRight, side, flush, children }: Props) {
  const ctx = useAppContext();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  const groups = Array.from(new Set(nav.map((n) => n.group ?? '')));
  const go = (key: string) => {
    onNavigate(key);
    setMobileOpen(false);
  };
  const current = nav.find((n) => n.key === active);

  return (
    <div className="cv-shell cv-root">
      {mobileOpen && <div className="cv-shell__scrim" onClick={() => setMobileOpen(false)} />}
      <div className={`cv-shell__side ${collapsed ? 'cv-shell__side--collapsed' : ''} ${mobileOpen ? 'cv-shell__side--open' : ''}`}>
        <Sidebar collapsed={collapsed}>
          <SidebarHeader type="brand-toggle" title={title} subtitle={subtitle} logo={<Bike size={18} />} collapsed={collapsed} onToggle={() => setCollapsed((c) => !c)} />
          {groups.map((g) => (
            <div key={g} style={{ display: 'contents' }}>
              {g && !collapsed && <SidebarGroupLabel>{g}</SidebarGroupLabel>}
              {nav
                .filter((n) => (n.group ?? '') === g)
                .map((n) => (
                  <SidebarItem key={n.key} icon={n.icon} label={n.label} badge={collapsed ? undefined : n.badge} active={n.key === active} collapsed={collapsed} onClick={() => go(n.key)} />
                ))}
            </div>
          ))}
          {side && !collapsed && (
            <>
              <SidebarDivider />
              {side}
            </>
          )}
          <SidebarFooter type="user-menu" title={ctx.user.name} subtitle={ctx.user.email || ctx.user.role} avatarInitials={initials(ctx.user.name)} collapsed={collapsed} onMenu={() => (window.location.href = ctx.urls.logout)} />
          {!collapsed && (
            <a href={ctx.urls.logout} className="cv-subtle" style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '4px 12px' }}>
              <LogOut size={12} /> Afmelden
            </a>
          )}
        </Sidebar>
      </div>
      <div className="cv-shell__main">
        <div className="cv-shell__top">
          <TopBar type="minimal" density="sm" showToggle={false} showActions={false} left={topLeft ?? <span className="cv-strong">{current?.label}</span>} rightSlot={topRight} account={<Avatar size="sm" initials={initials(ctx.user.name)} />} />
        </div>
        <div className="cv-shell__mobile">
          <TopBarMobile type="title" title={current?.label ?? title} onMenu={() => setMobileOpen(true)} trailing={<Avatar size="sm" initials={initials(ctx.user.name)} />} />
        </div>
        <main className={`cv-shell__content ${flush ? 'cv-shell__content--flush' : ''}`}>{children}</main>
      </div>
    </div>
  );
}
