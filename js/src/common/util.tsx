import app from 'flarum/common/app';
import Icon from 'flarum/common/components/Icon';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Mithril from 'mithril';

/** Flarum's own dayjs (with relativeTime + localizedFormat), not a second bundled copy. */
export const dayjs: any = (window as any).dayjs;

export const NS = 'ernestdefoe-member-desk';

export function t(key: string, params: Record<string, any> = {}): any {
  // Plurals need numbers; callers often pass formatted ones ("1,234"). ICU formats them back.
  const out: Record<string, any> = {};
  for (const [k, v] of Object.entries(params)) out[k] = typeof v === 'string' && /^\d{1,3}(,\d{3})*$/.test(v) ? Number(v.replace(/,/g, '')) : v;
  params = out;
  return app.translator.trans(`${NS}.lib.${key}`, params);
}

export function ts(key: string, params: Record<string, any> = {}): string {
  const out = t(key, params);
  return Array.isArray(out) ? out.join('') : String(out);
}

/** Member Desk's JSON API. */
export function api<T = any>(method: string, path: string, body?: any, params?: Record<string, any>): Promise<T> {
  return app.request<T>({
    method,
    url: `${app.forum.attribute('apiUrl')}/member-desk${path}`,
    body,
    params,
  } as any);
}

export { desk, setLinks, type DeskLinks } from './links';

export function num(n: number | null | undefined): string {
  return (n || 0).toLocaleString();
}

export function date(iso: string | null | undefined): string {
  return iso ? dayjs(iso).format('ll') : '—';
}

export function ago(iso: string | null | undefined): string {
  if (!iso) return ts('never');
  return dayjs(iso).fromNow();
}

export function isForever(iso: string | null | undefined): boolean {
  return !!iso && dayjs(iso).year() >= 2037;
}

export interface Row {
  id: number;
  username: string;
  displayName: string;
  avatarUrl: string | null;
  email: string;
  confirmed: boolean;
  groups: number[];
  joinedAt: string | null;
  lastSeenAt: string | null;
  posts: number;
  discussions: number;
  lastIp: string | null;
  suspendedUntil: string | null;
  anonymized: boolean;
  bannedIp: boolean;
  isAdmin: boolean;
  isYou: boolean;
  /** Reports against this member (Docket, or flarum/flags counts); null when not shown. */
  reports: { total: number; open: number } | null;
  /** Trust Levels' level (0–4), only for staff that extension lets see it. */
  trust: { level: number; locked: boolean; silenced: boolean } | null;
}

export interface TrustLevelName {
  level: number;
  name: string;
}

/** "Level 2 · Member", with a lock or a muted mark when Trust Levels holds the member there. */
export function trustPill(r: Row, names: TrustLevelName[] | null | undefined, compact = false): Mithril.Children {
  if (!r.trust || !names) return null;
  const name = names.find((n) => n.level === r.trust!.level)?.name || '';
  const full = ts('trust.pill', { level: r.trust.level, name }) + (r.trust.silenced ? ` · ${ts('trust.silenced')}` : '');
  return (
    <span className={`MemberDesk-pill MemberDesk-pill--trust${r.trust.silenced ? ' is-silenced' : ''}`} title={`${ts('trust.title')}: ${full}`}>
      <Icon name={r.trust.locked ? 'fas fa-lock' : 'fas fa-shield-halved'} />
      {compact ? name : t('trust.pill', { level: r.trust.level, name })}
      {r.trust.silenced ? <span className="MemberDesk-trustFlag">· {t('trust.silenced')}</span> : null}
    </span>
  );
}

export interface GroupInfo {
  id: number;
  name: string;
  plural: string;
  color: string | null;
  icon: string | null;
}

/** An avatar from plain data: the uploaded picture, or the first letter on the username's colour. */
export function avatar(who: { username: string; displayName: string; avatarUrl: string | null }, size: 'sm' | 'md' | 'lg' = 'md'): Mithril.Children {
  const cls = `MemberDesk-avatar MemberDesk-avatar--${size}`;
  if (who.avatarUrl) return <img className={cls} src={who.avatarUrl} alt="" loading="lazy" />;
  const letter = (who.displayName || who.username || '?').charAt(0).toUpperCase();
  return (
    <span className={cls} style={{ '--avatar-bg': `#${stringToColor(who.username || '?')}` }} aria-hidden="true">
      {letter}
    </span>
  );
}

export function groupChip(g: GroupInfo | undefined): Mithril.Children {
  if (!g) return null;
  return (
    <span className="MemberDesk-group" style={{ '--group-color': g.color || 'var(--muted-color)' }}>
      {g.icon ? <Icon name={g.icon} /> : null}
      {g.name}
    </span>
  );
}

/** Status pills: suspended, unconfirmed, anonymized, banned IP, or "Active". */
export function statusPills(r: Row, compact = false): Mithril.Children {
  const out: Mithril.Children[] = [];
  if (r.suspendedUntil) {
    out.push(
      <span className="MemberDesk-pill MemberDesk-pill--danger">
        <Icon name="fas fa-ban" />
        {compact || isForever(r.suspendedUntil)
          ? t('status.suspended_forever')
          : t('status.suspended_until', { date: dayjs(r.suspendedUntil).format('ll') })}
      </span>
    );
  }
  if (!r.confirmed && !r.anonymized) {
    out.push(
      <span className="MemberDesk-pill MemberDesk-pill--warn">
        <Icon name="far fa-envelope" />
        {t('status.unconfirmed')}
      </span>
    );
  }
  if (r.reports?.open) {
    out.push(
      <span className="MemberDesk-pill MemberDesk-pill--warn">
        <Icon name="fas fa-flag" />
        {t('status.open_reports', { count: r.reports.open })}
      </span>
    );
  }
  if (r.anonymized) out.push(<span className="MemberDesk-pill MemberDesk-pill--muted">{t('status.anonymized')}</span>);
  if (r.bannedIp) out.push(<span className="MemberDesk-pill MemberDesk-pill--danger">{t('status.banned_ip')}</span>);
  if (r.isAdmin && !compact) out.push(<span className="MemberDesk-pill MemberDesk-pill--info">{t('status.admin')}</span>);
  if (!out.length) out.push(<span className="MemberDesk-pill MemberDesk-pill--ok">{t('status.active')}</span>);
  return out;
}

export function errorText(e: any): string {
  const err = e?.response?.errors?.[0];
  if (err?.detail) return err.detail;
  return ts('error.generic');
}
