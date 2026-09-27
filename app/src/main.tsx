import { StrictMode, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource-variable/geist';
import '@sakaniui/react/tokens.css';
import '@sakaniui/react/style.css';
import './ui/global.css';
import { readConfig, type Api } from './api/client';
import { WpApi } from './api/wp';
import { createMockApi } from './mock/api';
import type { AppContext } from './domain/types';
import { ApiProvider } from './lib/api-context';
import { ToastProvider } from './lib/toast';

// One bundle serves five mount points; each view loads its own chunk (Recharts only ships with the ops desk).
const OpsApp = lazy(() => import('./ops/OpsApp').then((m) => ({ default: m.OpsApp })));
const PortalApp = lazy(() => import('./portal/PortalApp').then((m) => ({ default: m.PortalApp })));
const CourierApp = lazy(() => import('./courier/CourierApp').then((m) => ({ default: m.CourierApp })));
const BookingWidget = lazy(() => import('./public/BookingWidget').then((m) => ({ default: m.BookingWidget })));
const TrackingWidget = lazy(() => import('./public/TrackingWidget').then((m) => ({ default: m.TrackingWidget })));

type View = 'ops' | 'portal' | 'courier' | 'booking' | 'tracking';
const ROLE_FOR_VIEW: Record<View, string> = { ops: 'dispatcher', portal: 'customer', courier: 'courier', booking: 'guest', tracking: 'guest' };

function render(el: HTMLElement, view: View, api: Api, context: AppContext, isMock: boolean) {
  const token = el.dataset.token ?? new URLSearchParams(location.search).get('t') ?? '';
  const page = {
    ops: <OpsApp />,
    portal: <PortalApp />,
    courier: <CourierApp />,
    booking: <BookingWidget />,
    tracking: <TrackingWidget token={token} />,
  }[view];
  createRoot(el).render(
    <StrictMode>
      <ApiProvider api={api} context={context}>
        <ToastProvider>
          <Suspense fallback={<div className="cv-root cv-muted" style={{ padding: 24 }}>Laden…</div>}>{page}</Suspense>
          {isMock && <DevBar view={view} />}
        </ToastProvider>
      </ApiProvider>
    </StrictMode>,
  );
}

function DevBar({ view }: { view: View }) {
  const views: View[] = ['ops', 'portal', 'courier', 'booking', 'tracking'];
  return (
    <div className="cv-devbar">
      {views.map((v) => (
        <a key={v} href={`?view=${v}`} className={v === view ? 'active' : ''}>{v}</a>
      ))}
    </div>
  );
}

async function boot() {
  const config = readConfig();
  const mounts = Array.from(document.querySelectorAll<HTMLElement>('[data-cargovelo-view], #cargovelo-app'));
  for (const el of mounts) {
    const isMock = config.mode === 'mock' || el.dataset.cargoveloMode === 'mock';
    const view = ((el.dataset.cargoveloView as View | undefined) ?? (new URLSearchParams(location.search).get('view') as View | null) ?? (isMock ? 'ops' : 'portal')) as View;
    el.classList.add('cv-root');
    if (isMock) {
      // Dev harness: ?view=ops&role=admin to see the admin-only pages.
      const api = createMockApi(new URLSearchParams(location.search).get('role') ?? ROLE_FOR_VIEW[view]);
      render(el, view, api, await api.me(), true);
    } else {
      const api = new WpApi(config);
      const context = config.context ?? (await api.me());
      render(el, view, api, context, false);
    }
  }
}

boot();
