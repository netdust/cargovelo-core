import { AlertTriangle, CheckCircle2, Package, Timer, Truck } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { BarChart, DonutChart, Skeleton, StatCard } from '@sakaniui/react';
import { useApi } from '../lib/api-context';
import { useLoad } from '../lib/useLoad';
import { SERVICE_LABEL } from '../domain/status';
import type { ServiceCode } from '../domain/types';
import { PageHeader, Section } from '../ui/Page';
import { RecentRequests } from './RecentRequests';

export function DashboardPage() {
  const api = useApi();
  const nav = useNavigate();
  const stats = useLoad(() => api.opsDashboard(), []);
  const d = stats.data;

  return (
    <div>
      <PageHeader title="Dashboard" description="Vandaag over alle hubs" />
      <div className="cv-grid cv-grid--stats" style={{ marginBottom: 24 }}>
        {d ? (
          <>
            <StatCard variant="icon" icon={Package} title="Zendingen vandaag" value={String(d.today_total)} description={`${d.today_open} nog open`} badgeVariant="neutral" delta={`${d.today_open} open`} trend="flat" />
            <StatCard variant="icon" icon={CheckCircle2} title="Geleverd" value={String(d.today_delivered)} description="vandaag" trend="up" delta={d.today_total ? `${Math.round((d.today_delivered / d.today_total) * 100)}%` : '0%'} />
            <StatCard variant="icon" icon={Timer} title="On-time" value={`${(d.on_time_rate * 100).toFixed(1)}%`} description="laatste 30 dagen" trend={d.on_time_rate >= 0.95 ? 'up' : 'down'} delta={d.on_time_rate >= 0.95 ? 'OK' : 'onder 95%'} />
            <StatCard variant="icon" icon={AlertTriangle} title="Uitzonderingen" value={String((d as { exceptions?: number }).exceptions ?? d.volume_7d.reduce((n, v) => n + (v.value2 ?? 0), 0))} description="open, actie nodig" trend={((d as { exceptions?: number }).exceptions ?? 0) > 0 ? 'down' : 'flat'} delta="nu" onMenu={() => nav('/shipments?exception=1')} />
            <StatCard variant="icon" icon={Truck} title="Website-aanvragen" value={String(((d as { by_status?: Record<string, number> }).by_status?.requested) ?? 0)} description="wachten op bevestiging" trend="flat" delta="te bevestigen" />
          </>
        ) : (
          Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} variant="rect" height={120} />)
        )}
      </div>
      <div className="cv-grid cv-grid--2">
        <Section title="Volume laatste 7 dagen" className="cv-panel">
          {d ? <BarChart variant="stacked" size="md" data={d.volume_7d.map((v) => ({ label: v.label, value: v.value - (v.value2 ?? 0), value2: v.value2 ?? 0 }))} seriesLabels={['Zendingen', 'Mislukt']} /> : <Skeleton variant="rect" height={240} />}
        </Section>
        <Section title="Per dienst (30 dagen)" className="cv-panel">
          {d ? (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 16, alignItems: 'center' }}>
              <DonutChart size="md" data={d.by_service.map((s) => ({ label: SERVICE_LABEL[s.label as ServiceCode] ?? s.label, value: s.value }))} centerValue={String(d.by_service.reduce((n, s) => n + s.value, 0))} centerCaption="zendingen" />
              <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 6 }}>
                {d.by_service.map((s, i) => (
                  <li key={s.label} style={{ display: 'flex', gap: 8, alignItems: 'center' }}><span className="cv-dot" style={{ background: `var(--color-chart-${(i % 5) + 1})` }} />{SERVICE_LABEL[s.label as ServiceCode] ?? s.label}<span className="cv-muted">{s.value}</span></li>
                ))}
              </ul>
            </div>
          ) : <Skeleton variant="rect" height={240} />}
        </Section>
        <Section title="Per hub (30 dagen)" className="cv-panel">
          {d ? <BarChart variant="horizontal" size="md" data={d.by_hub} /> : <Skeleton variant="rect" height={240} />}
        </Section>
        <Section title="Recente website-aanvragen" className="cv-panel">
          <RecentRequests />
        </Section>
      </div>
    </div>
  );
}
