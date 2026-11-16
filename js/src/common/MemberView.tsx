import app from 'flarum/common/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Tooltip from 'flarum/common/components/Tooltip';
import ItemList from 'flarum/common/utils/ItemList';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Mithril from 'mithril';
import ConfirmModal, { reportRun } from './ConfirmModal';
import * as tabs from './memberTabs';
import { deskState } from './DeskState';
import { ago, api, avatar, date, dayjs, desk, errorText, groupChip, isForever, num, statusPills, t, trustPill, ts, type GroupInfo } from './util';

export interface MemberViewAttrs extends ComponentAttrs {
  id: number;
}

const TABS = ['profile', 'groups', 'activity', 'reports', 'ips', 'notes', 'warnings', 'log'];

/**
 * One member, everything about them: a hero with who they are and where they stand (status,
 * email verification, the numbers that matter, a year of activity), then tabs for profile,
 * groups, activity, IPs and other accounts, notes, warnings and the admin log.
 */
export default class MemberView extends Component<MemberViewAttrs> {
  data: any = null;
  error: string | null = null;
  tab = 'profile';
  noteText = '';
  noteSaving = false;
  busy: string | null = null;
  loadedId = 0;

  oninit(vnode: Mithril.Vnode<MemberViewAttrs, this>) {
    super.oninit(vnode);
    const tab = m.route.param('tab');
    if (tab && TABS.includes(tab)) this.tab = tab;
    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<MemberViewAttrs, this>) {
    super.onbeforeupdate(vnode as any);
    if (vnode.attrs.id !== this.loadedId) {
      this.data = null;
      this.load();
    }
    return true;
  }

  load() {
    const id = this.attrs.id;
    this.loadedId = id;
    this.error = null;
    return api('GET', `/members/${id}`)
      .then((d) => {
        if (id !== this.loadedId) return;
        this.data = d;
        // Share the groups and abilities with the list, so going back needs no extra request.
        if (!deskState().meta && d.meta) deskState().meta = d.meta;
      })
      .catch((e) => (this.error = e?.response?.errors?.[0]?.status === '404' ? ts('member.not_found') : errorText(e)))
      .finally(() => m.redraw());
  }

  groups(): GroupInfo[] {
    return this.data?.meta?.groups || [];
  }

  group(id: number) {
    return this.groups().find((g) => g.id === id);
  }

  view() {
    if (this.error) {
      return (
        <div className="MemberDesk MemberDesk--member">
          {this.back()}
          <p className="MemberDesk-error">{this.error}</p>
        </div>
      );
    }
    if (!this.data) {
      return (
        <div className="MemberDesk MemberDesk--member">
          {this.back()}
          <LoadingIndicator display="block" />
        </div>
      );
    }

    const d = this.data;
    const r = d.member;
    const color = `#${stringToColor(r.username)}`;

    return (
      <div className="MemberDesk MemberDesk--member" style={{ '--member-color': color }}>
        {this.back()}
        <div className="MemberDesk-scope">
          {this.hero()}
          {this.banners()}
          <nav className="MemberDesk-tabs MemberDesk-tabs--member" role="tablist" aria-label={ts('member.sections')}>
            {this.tabs().map(([key, label, badge]) => (
              <button
                type="button"
                role="tab"
                id={`md-tab-${key}`}
                aria-selected={this.tab === key ? 'true' : 'false'}
                aria-controls="md-tabpanel"
                className={this.tab === key ? 'is-active' : ''}
                onclick={() => (this.tab = key)}
              >
                {label}
                {badge ? <span className="MemberDesk-tabBadge">{badge}</span> : null}
              </button>
            ))}
          </nav>
          <div id="md-tabpanel" role="tabpanel" aria-labelledby={`md-tab-${this.tab}`} className="MemberDesk-panel">
            {this.panel()}
          </div>
        </div>
      </div>
    );
  }

  back() {
    return (
      <a className="MemberDesk-back" href={desk().list()} onclick={(e: MouseEvent) => this.go(e, desk().list())}>
        <Icon name="fas fa-arrow-left" /> {t('member.back')}
      </a>
    );
  }

  go(e: MouseEvent, href: string) {
    if (e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    const i = href.indexOf('#');
    if (i >= 0) return m.route.set(href.slice(i + 1));
    const base = new URL(app.forum.attribute('baseUrl')).pathname.replace(/\/$/, '');
    m.route.set(new URL(href, window.location.href).pathname.replace(base, '') || '/');
  }

  tabs(): [string, Mithril.Children, Mithril.Children][] {
    const d = this.data;
    const list: [string, Mithril.Children, Mithril.Children][] = [
      ['profile', t('tab.profile'), null],
      ['groups', t('tab.groups'), d.member.groups.length || null],
      ['activity', t('tab.activity'), null],
    ];
    if (d.reports) list.push(['reports', t('tab.reports'), d.reports.open || null]);
    if (d.ips !== null) list.push(['ips', t('tab.ips'), d.ips.some((i: any) => i.others.length) ? <Icon name="fas fa-user-group" /> : null]);
    if (d.notes !== null) list.push(['notes', t('tab.notes'), d.notes.items.length || null]);
    list.push(['warnings', t('tab.warnings'), d.warnings?.strikes || (d.member.suspendedUntil ? '!' : null)]);
    list.push(['log', t('tab.log'), d.log.length || null]);
    return list;
  }

  /** The hero: identity, status and verification, actions, and the numbers. */
  hero(): Mithril.Children {
    const d = this.data;
    const r = d.member;
    const online = !!r.lastSeenAt && dayjs().diff(dayjs(r.lastSeenAt), 'minute') < 5;

    return (
      <header className="MemberDesk-hero">
        <div className="MemberDesk-heroBand" aria-hidden="true" />
        <div className="MemberDesk-heroMain">
          <div className="MemberDesk-heroAvatar">
            {avatar(r, 'lg')}
            {online ? <span className="MemberDesk-online" title={ts('member.online')} /> : null}
          </div>
          <div className="MemberDesk-heroId">
            <h2 className="MemberDesk-heroName">{r.displayName}</h2>
            <div className="MemberDesk-heroHandle">
              <span>@{r.username}</span>
              <span className="MemberDesk-dot" aria-hidden="true">
                ·
              </span>
              <span>{t('member.id', { id: r.id })}</span>
            </div>
            {this.heroEmail()}
            <div className="MemberDesk-heroBadges">
              {r.groups.map((id: number) => groupChip(this.group(id)))}
              {statusPills(r)}
              {trustPill(r, d.meta.trustLevels)}
            </div>
          </div>
          <div className="MemberDesk-heroActions">
            {this.heroActions(r, d.can).toArray()}
            {this.moreMenu()}
          </div>
        </div>
        {this.heroStats(online)}
      </header>
    );
  }

  /** The email line: the address, whether it's verified, and confirm/copy buttons. */
  heroEmail(): Mithril.Children {
    const d = this.data;
    const r = d.member;

    return (
      <div className="MemberDesk-heroEmail">
        <Icon name="far fa-envelope" />
        <span className="MemberDesk-emailText">{r.email}</span>
        {r.confirmed ? (
          <span className="MemberDesk-verify MemberDesk-verify--ok">
            <Icon name="fas fa-circle-check" /> {t('member.verified')}
          </span>
        ) : (
          <span className="MemberDesk-verify MemberDesk-verify--no">
            <Icon name="fas fa-circle-exclamation" /> {t('member.not_verified')}
          </span>
        )}
        {!r.confirmed && d.can.confirm ? (
          <Button className="Button Button--small MemberDesk-confirmBtn" loading={this.busy === 'confirm'} onclick={() => this.quick('confirm')}>
            {t('member.confirm_email')}
          </Button>
        ) : null}
        {d.meta.can.emails ? (
          <Tooltip text={ts('member.copy_email')}>
            <Button
              className="Button Button--icon Button--link Button--small"
              icon="far fa-copy"
              aria-label={ts('member.copy_email')}
              onclick={() => this.copy(r.email)}
            />
          </Tooltip>
        ) : null}
      </div>
    );
  }

  /** The numbers: posts, discussions, likes, joined, last seen, and a year of activity. */
  heroStats(online: boolean): Mithril.Children {
    const d = this.data;
    const r = d.member;
    const months = d.activity.months as { month: string; posts: number }[];
    const peak = Math.max(1, ...months.map((x) => x.posts));
    const yearPosts = months.reduce((a, x) => a + x.posts, 0);
    const stat = (label: Mithril.Children, value: Mithril.Children, sub?: Mithril.Children, title?: string) => (
      <div className="MemberDesk-stat">
        <dt>{label}</dt>
        <dd title={title}>{value}</dd>
        {sub}
      </div>
    );

    return (
      <dl className="MemberDesk-stats">
        {stat(t('member.stat_posts'), num(r.posts))}
        {stat(t('member.stat_discussions'), num(r.discussions))}
        {d.activity.likes ? stat(t('member.stat_likes'), num(d.activity.likes.received)) : null}
        {stat(t('member.stat_joined'), date(r.joinedAt), <span className="MemberDesk-statSub">{ago(r.joinedAt)}</span>, r.joinedAt)}
        {stat(
          t('member.stat_seen'),
          online ? t('member.online_now') : r.lastSeenAt ? ago(r.lastSeenAt) : t('list.hidden'),
          r.lastIp ? <span className="MemberDesk-statSub MemberDesk-mono">{r.lastIp}</span> : null
        )}
        <div className="MemberDesk-stat MemberDesk-stat--spark">
          <dt>{t('member.stat_year', { count: num(yearPosts) })}</dt>
          <dd className="MemberDesk-spark" role="img" aria-label={ts('member.spark_label', { count: num(yearPosts) })}>
            {months.map((x) => (
              <span style={{ '--h': `${Math.round((x.posts / peak) * 100)}%` }} title={`${dayjs(x.month + '-01').format('MMM YYYY')}: ${x.posts}`} />
            ))}
          </dd>
        </div>
      </dl>
    );
  }

  /**
   * The buttons in the member page header, as an ItemList so other extensions can add their own
   * (e.g. a link to that member's permissions).
   */
  heroActions(r: any, can: Record<string, boolean>): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();
    if (!r.anonymized) {
      items.add(
        'profile',
        <a className="Button" href={desk().profile(r.username)} target={desk().admin ? '_blank' : undefined} rel="noopener">
          <Icon name="far fa-user" className="Button-icon" />
          <span className="Button-label">{t('member.view_profile')}</span>
        </a>,
        100
      );
    }
    if (r.suspendedUntil) {
      if (can.unsuspend) {
        items.add(
          'unsuspend',
          <Button className="Button" icon="fas fa-unlock" loading={this.busy === 'unsuspend'} onclick={() => this.quick('unsuspend')}>
            {t('member.unsuspend')}
          </Button>,
          50
        );
      }
    } else if (can.suspend) {
      items.add(
        'suspend',
        <Button className="Button" icon="fas fa-ban" onclick={() => this.confirmAction('suspend')}>
          {t('member.suspend')}
        </Button>,
        50
      );
    }
    return items;
  }

  moreMenu(): Mithril.Children {
    const d = this.data;
    const can = d.can;
    const items: Mithril.Children[] = [];
    const spam = this.lastSpam();

    if (can.confirm && !d.member.confirmed)
      items.push(
        <Button icon="fas fa-envelope-circle-check" onclick={() => this.quick('confirm')}>
          {t('member.confirm_email')}
        </Button>
      );
    if (can.signOut)
      items.push(
        <Button icon="fas fa-right-from-bracket" onclick={() => this.confirmAction('signOut')}>
          {t('member.sign_out')}
        </Button>
      );
    if (spam && !spam.after?.restored && can.unspam)
      items.push(
        <Button icon="fas fa-rotate-left" onclick={() => this.confirmAction('unspam')}>
          {t('member.unspam')}
        </Button>
      );
    if (can.spammer && !d.member.anonymized)
      items.push(
        <Button icon="fas fa-skull-crossbones" className="MemberDesk-menuDanger" onclick={() => this.confirmAction('spammer')}>
          {t('member.spammer')}
        </Button>
      );
    if (can.anonymize)
      items.push(
        <Button icon="fas fa-user-secret" className="MemberDesk-menuDanger" onclick={() => this.confirmAction('anonymize')}>
          {t('member.anonymize')}
        </Button>
      );
    if (can.delete || (can.anonymize && d.meta.gdprDeletionOff))
      items.push(
        <Button icon="far fa-trash-can" className="MemberDesk-menuDanger" onclick={() => this.confirmAction('delete')}>
          {t('member.delete')}
        </Button>
      );
    if (d.member.isAdmin && (d.meta.can.anonymize || d.meta.can.delete))
      items.push(<span className="MemberDesk-menuNote">{t('member.admin_protected')}</span>);
    if (d.member.isYou) items.push(<span className="MemberDesk-menuNote">{t('member.you_protected')}</span>);

    if (!items.length) return null;

    return (
      <Dropdown
        className="MemberDesk-more"
        buttonClassName="Button Button--icon"
        menuClassName="Dropdown-menu--right"
        icon="fas fa-ellipsis"
        accessibleToggleLabel={ts('member.more')}
        label=""
      >
        {items}
      </Dropdown>
    );
  }

  lastSpam(): any {
    return (this.data.log || []).find((e: any) => e.action === 'spammer' && e.status === 'done');
  }

  banners(): Mithril.Children {
    const d = this.data;
    const r = d.member;
    const out: Mithril.Children[] = [];

    if (r.suspendedUntil) {
      out.push(
        <div className="MemberDesk-banner MemberDesk-banner--danger" role="status">
          <Icon name="fas fa-ban" />
          <div>
            <b>
              {isForever(r.suspendedUntil)
                ? t('member.banner_suspended_forever')
                : t('member.banner_suspended', { date: date(r.suspendedUntil), ago: ago(r.suspendedUntil) })}
            </b>
            {d.suspension?.reason ? <span className="MemberDesk-bannerSub">{t('member.banner_reason', { reason: d.suspension.reason })}</span> : null}
          </div>
        </div>
      );
    }

    const spam = this.lastSpam();
    if (spam && !spam.after?.restored && d.can.unspam) {
      out.push(
        <div className="MemberDesk-banner MemberDesk-banner--warn" role="status">
          <Icon name="fas fa-skull-crossbones" />
          <div>
            <b>{t('member.banner_spammer', { date: date(spam.createdAt), by: spam.actor?.displayName || '—' })}</b>
            <span className="MemberDesk-bannerSub">
              {t('member.banner_spammer_sub', {
                posts: num(spam.after?.posts || 0),
                discussions: num(spam.after?.discussions || 0),
                ips: num(spam.after?.bannedIps || 0),
              })}
            </span>
          </div>
          <Button className="Button Button--small" onclick={() => this.confirmAction('unspam')}>
            {t('member.unspam')}
          </Button>
        </div>
      );
    }

    if (d.erasure && ['awaiting_user_confirmation', 'user_confirmed'].includes(d.erasure.status)) {
      out.push(
        <div className="MemberDesk-banner MemberDesk-banner--info" role="status">
          <Icon name="fas fa-user-shield" />
          <div>
            <b>{t(`member.banner_erasure_${d.erasure.status}`, { date: date(d.erasure.createdAt) })}</b>
          </div>
        </div>
      );
    }

    if (r.anonymized) {
      out.push(
        <div className="MemberDesk-banner MemberDesk-banner--info" role="status">
          <Icon name="fas fa-user-secret" />
          <div>
            <b>{t('member.banner_anonymized')}</b>
          </div>
        </div>
      );
    }

    return out.length ? <div className="MemberDesk-banners">{out}</div> : null;
  }

  panel(): Mithril.Children {
    switch (this.tab) {
      case 'groups':
        return tabs.groupsTab(this);
      case 'activity':
        return tabs.activityTab(this);
      case 'reports':
        return tabs.reportsTab(this);
      case 'ips':
        return tabs.ipsTab(this);
      case 'notes':
        return tabs.notesTab(this);
      case 'warnings':
        return tabs.warningsTab(this);
      case 'log':
        return tabs.logTab(this);
      default:
        return tabs.profileTab(this);
    }
  }

  confirmAction(action: string) {
    app.modal.show(ConfirmModal, {
      action,
      target: { ids: [this.data.member.id] },
      groups: this.groups(),
      gdprDeletionOff: this.data.meta.gdprDeletionOff,
      onDone: (res: any) =>
        reportRun(res, () => {
          if (res.action === 'delete' && !res.failed?.length) {
            deskState().load();
            m.route.set(this.routePath(desk().list()));
            return;
          }
          this.load();
          deskState().load();
        }),
    });
  }

  routePath(href: string): string {
    const i = href.indexOf('#');
    if (i >= 0) return href.slice(i + 1);
    const base = new URL(app.forum.attribute('baseUrl')).pathname.replace(/\/$/, '');
    return new URL(href, window.location.href).pathname.replace(base, '') || '/';
  }

  quick(action: string, params: Record<string, any> = {}) {
    this.busy = action;
    return api('POST', '/run', { action, target: { ids: [this.data.member.id] }, params })
      .then((res: any) => {
        if (res.failed?.length) app.alerts.show({ type: 'error' }, t(`skip.${res.failed[0].reason}`));
        else app.alerts.show({ type: 'success' }, t(`done.${action}`));
        return this.load();
      })
      .catch((e) => app.alerts.show({ type: 'error' }, errorText(e)))
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }

  toggleGroup(id: number, on: boolean) {
    this.busy = `g${id}`;
    api('POST', '/run', { action: on ? 'removeGroup' : 'addGroup', target: { ids: [this.data.member.id] }, params: { group: id } })
      .then((res: any) => {
        if (res.failed?.length) app.alerts.show({ type: 'error' }, t(`skip.${res.failed[0].reason}`));
        return this.load();
      })
      .catch((e) => app.alerts.show({ type: 'error' }, errorText(e)))
      .finally(() => {
        this.busy = null;
        deskState().load();
        m.redraw();
      });
  }

  banIp(ip: string) {
    if (!confirm(ts('ips.ban_confirm', { ip }))) return;
    this.busy = `ban${ip}`;
    api('POST', '/ban-ip', { ip, userId: this.data.member.id })
      .then(() => {
        app.alerts.show({ type: 'success' }, t('ips.banned_done', { ip }));
        return this.load();
      })
      .catch((e) => app.alerts.show({ type: 'error' }, errorText(e)))
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }

  addNote(e: SubmitEvent) {
    e.preventDefault();
    const note = this.noteText.trim();
    if (!note) return;
    this.noteSaving = true;
    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/moderatorNote`,
        body: { data: { type: 'moderatorNote', attributes: { userId: this.data.member.id, note } } },
      })
      .then(() => {
        this.noteText = '';
        return this.load();
      })
      .catch((err) => app.alerts.show({ type: 'error' }, errorText(err)))
      .finally(() => {
        this.noteSaving = false;
        m.redraw();
      });
  }

  copy(text: string) {
    navigator.clipboard?.writeText(text).then(
      () => app.alerts.show({ type: 'success' }, t('member.copied')),
      () => null
    );
  }
}

export { describe } from './memberTabs';
