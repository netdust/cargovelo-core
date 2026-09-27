import { HashRouter, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { AlertTriangle, Bike, Building2, Download, LayoutDashboard, Package, Settings, Tag, Truck } from 'lucide-react';
import { AppShell, type NavItem } from '../ui/AppShell';
import { useApi, useAppContext } from '../lib/api-context';
import { useLoad } from '../lib/useLoad';
import { ShipmentsPage } from './ShipmentsPage';
import { DispatchBoard } from './DispatchBoard';
import { DashboardPage } from './DashboardPage';
import { CustomersPage, CouriersPage, PricesPage, SettingsPage } from './MasterData';
import { ExportPage } from './ExportPage';

export function OpsApp() {
  return (
    <HashRouter>
      <Frame />
    </HashRouter>
  );
}

function Frame() {
  const api = useApi();
  const ctx = useAppContext();
  const nav = useNavigate();
  const loc = useLocation();
  const counts = useLoad(() => api.opsCounts(), [loc.pathname]);
  const open = counts.data ? ['requested', 'confirmed', 'assigned', 'picked_up', 'in_transit'].reduce((n, s) => n + (counts.data!.by_status[s as keyof typeof counts.data.by_status] ?? 0), 0) : 0;
  const isAdmin = ctx.user.role === 'admin';

  const items: NavItem[] = [
    { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, group: 'Operations' },
    { key: 'shipments', label: 'Zendingen', icon: Package, badge: open ? String(open) : undefined, group: 'Operations' },
    { key: 'dispatch', label: 'Dispatch', icon: Truck, group: 'Operations' },
    { key: 'shipments?exception=1', label: 'Uitzonderingen', icon: AlertTriangle, badge: counts.data?.exceptions ? String(counts.data.exceptions) : undefined, group: 'Operations' },
    { key: 'customers', label: 'Klanten', icon: Building2, group: 'Beheer' },
    { key: 'couriers', label: 'Koeriers', icon: Bike, group: 'Beheer' },
    { key: 'export', label: 'Export', icon: Download, group: 'Beheer' },
    ...(isAdmin ? [{ key: 'prices', label: 'Prijslijsten', icon: Tag, group: 'Beheer' }, { key: 'settings', label: 'Instellingen', icon: Settings, group: 'Beheer' }] : []),
  ];
  const active = loc.pathname.startsWith('/shipments') && loc.search.includes('exception=1') ? 'shipments?exception=1' : loc.pathname.split('/')[1] || 'dashboard';

  return (
    <AppShell title="Cargo Velo" subtitle="Dispatch" nav={items} active={active} onNavigate={(k) => nav('/' + k)} flush={loc.pathname.startsWith('/dispatch')}>
      <Routes>
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<DashboardPage />} />
        <Route path="/shipments" element={<ShipmentsPage onChanged={counts.reload} />} />
        <Route path="/shipments/:id" element={<ShipmentsPage onChanged={counts.reload} />} />
        <Route path="/dispatch" element={<DispatchBoard />} />
        <Route path="/customers" element={<CustomersPage />} />
        <Route path="/couriers" element={<CouriersPage />} />
        <Route path="/export" element={<ExportPage />} />
        <Route path="/prices" element={<PricesPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </AppShell>
  );
}
