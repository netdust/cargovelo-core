import { Badge } from '@sakaniui/react';
import { STATUS_LABEL, STATUS_TONE } from '../domain/status';
import type { ShipmentStatus } from '../domain/types';

export function StatusBadge({ status, solid = false }: { status: ShipmentStatus; solid?: boolean }) {
  return (
    <Badge variant={STATUS_TONE[status]} emphasis={solid ? 'solid' : 'subtle'}>
      {STATUS_LABEL[status]}
    </Badge>
  );
}

export function StatusDot({ status }: { status: ShipmentStatus }) {
  return <span className="cv-dot" style={{ background: `var(--cv-status-${status})` }} aria-hidden="true" />;
}
