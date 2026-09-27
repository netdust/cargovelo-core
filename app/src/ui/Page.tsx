import type { ReactNode } from 'react';

export function PageHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="cv-page__head">
      <div>
        <h1 className="cv-page__title">{title}</h1>
        {description && <p className="cv-page__desc">{description}</p>}
      </div>
      {actions && <div className="cv-page__actions">{actions}</div>}
    </div>
  );
}

export function Section({ title, children, actions, className }: { title?: ReactNode; children: ReactNode; actions?: ReactNode; className?: string }) {
  return (
    <section className={`cv-section ${className ?? ''}`}>
      {(title || actions) && (
        <div className="cv-section__head">
          {title && <h2 className="cv-section__title">{title}</h2>}
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}

export function KeyValue({ rows }: { rows: Array<[ReactNode, ReactNode]> }) {
  return (
    <dl className="cv-kv">
      {rows.map(([k, v], i) => (
        <div key={i} className="cv-kv__row">
          <dt>{k}</dt>
          <dd>{v}</dd>
        </div>
      ))}
    </dl>
  );
}

export function Inline({ children, gap = 8, wrap = true, align = 'center', justify }: { children: ReactNode; gap?: number; wrap?: boolean; align?: string; justify?: string }) {
  return (
    <div style={{ display: 'flex', gap, flexWrap: wrap ? 'wrap' : 'nowrap', alignItems: align, justifyContent: justify }}>{children}</div>
  );
}

export function Stack({ children, gap = 12 }: { children: ReactNode; gap?: number }) {
  return <div style={{ display: 'flex', flexDirection: 'column', gap }}>{children}</div>;
}
