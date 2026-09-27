import { useNavigate } from 'react-router-dom';
import { Button, EmptyState, ListItem } from '@sakaniui/react';
import { useApi } from '../lib/api-context';
import { useLoad } from '../lib/useLoad';
import { relativeTime } from '../lib/format';
import { StatusBadge } from '../ui/StatusBadge';

export function RecentRequests() {
  const api = useApi();
  const nav = useNavigate();
  const list = useLoad(() => api.opsList({ status: ['requested'], per_page: 6, order: 'desc' }), []);
  if (list.data && list.data.items.length === 0) return <EmptyState type="no-data" title="Geen open aanvragen" description="Alle website-aanvragen zijn bevestigd." />;
  return (
    <div style={{ display: 'grid', gap: 4 }}>
      {(list.data?.items ?? []).map((s) => (
        <ListItem key={s.id} title={`${s.reference} · ${s.customer_name}`} description={`${s.pickup.city} → ${s.delivery.city} · ${relativeTime(s.created_at)}`} trailing={<StatusBadge status={s.status} />} onClick={() => nav(`/shipments/${s.id}?status=requested`)} />
      ))}
      {list.data && list.data.total > 6 && <Button variant="ghost" size="sm" onClick={() => nav('/shipments?status=requested')}>Alle {list.data.total} aanvragen</Button>}
    </div>
  );
}
