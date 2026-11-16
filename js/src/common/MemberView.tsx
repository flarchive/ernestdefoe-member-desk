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
    const can = d.can;
    const online = r.lastSeenAt && dayjs().diff(dayjs(r.lastSeenAt), 'minute') < 5;
    const months = d.activity.months as { month: string; posts: number }[];
    const peak = Math.max(1, ...months.map((x) => x.posts));
    const yearPosts = months.reduce((a, x) => a + x.posts, 0);

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
              {!r.confirmed && can.confirm ? (
                <Button
                  className="Button Button--small MemberDesk-confirmBtn"
                  loading={this.busy === 'confirm'}
                  onclick={() => this.quick('confirm')}
                >
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
            <div className="MemberDesk-heroBadges">
              {r.groups.map((id: number) => groupChip(this.group(id)))}
              {statusPills(r)}
              {trustPill(r, d.meta.trustLevels)}
            </div>
          </div>
          <div className="MemberDesk-heroActions">
            {this.heroActions(r, can).toArray()}
            {this.moreMenu()}
          </div>
        </div>

        <dl className="MemberDesk-stats">
          <div className="MemberDesk-stat">
            <dt>{t('member.stat_posts')}</dt>
            <dd>{num(r.posts)}</dd>
          </div>
          <div className="MemberDesk-stat">
            <dt>{t('member.stat_discussions')}</dt>
            <dd>{num(r.discussions)}</dd>
          </div>
          {d.activity.likes ? (
            <div className="MemberDesk-stat">
              <dt>{t('member.stat_likes')}</dt>
              <dd>{num(d.activity.likes.received)}</dd>
            </div>
          ) : null}
          <div className="MemberDesk-stat">
            <dt>{t('member.stat_joined')}</dt>
            <dd title={r.joinedAt}>{date(r.joinedAt)}</dd>
            <span className="MemberDesk-statSub">{ago(r.joinedAt)}</span>
          </div>
          <div className="MemberDesk-stat">
            <dt>{t('member.stat_seen')}</dt>
            <dd>{online ? t('member.online_now') : r.lastSeenAt ? ago(r.lastSeenAt) : t('list.hidden')}</dd>
            {r.lastIp ? <span className="MemberDesk-statSub MemberDesk-mono">{r.lastIp}</span> : null}
          </div>
          <div className="MemberDesk-stat MemberDesk-stat--spark">
            <dt>{t('member.stat_year', { count: num(yearPosts) })}</dt>
            <dd className="MemberDesk-spark" role="img" aria-label={ts('member.spark_label', { count: num(yearPosts) })}>
              {months.map((x) => (
                <span
                  style={{ '--h': `${Math.round((x.posts / peak) * 100)}%` }}
                  title={`${dayjs(x.month + '-01').format('MMM YYYY')}: ${x.posts}`}
                />
              ))}
            </dd>
          </div>
        </dl>
      </header>
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
        return this.groupsTab();
      case 'activity':
        return this.activityTab();
      case 'reports':
        return this.reportsTab();
      case 'ips':
        return this.ipsTab();
      case 'notes':
        return this.notesTab();
      case 'warnings':
        return this.warningsTab();
      case 'log':
        return this.logTab();
      default:
        return this.profileTab();
    }
  }

  profileTab() {
    const d = this.data;
    const r = d.member;
    const p = d.profile;
    return (
      <div className="MemberDesk-grid">
        <section className="MemberDesk-card">
          <h3>{t('profile.account')}</h3>
          <dl className="MemberDesk-kv">
            <dt>{t('profile.display_name')}</dt>
            <dd>{r.displayName}</dd>
            <dt>{t('profile.username')}</dt>
            <dd>@{r.username}</dd>
            <dt>{t('profile.email')}</dt>
            <dd>
              <span className="MemberDesk-emailText">{r.email}</span>{' '}
              {r.confirmed ? (
                <span className="MemberDesk-verify MemberDesk-verify--ok">{t('member.verified')}</span>
              ) : (
                <span className="MemberDesk-verify MemberDesk-verify--no">{t('member.not_verified')}</span>
              )}
            </dd>
            <dt>{t('profile.joined')}</dt>
            <dd>
              {date(r.joinedAt)} <span className="MemberDesk-muted">({ago(r.joinedAt)})</span>
            </dd>
            <dt>{t('profile.seen')}</dt>
            <dd>{r.lastSeenAt ? `${date(r.lastSeenAt)} (${ago(r.lastSeenAt)})` : t('list.hidden')}</dd>
            {r.trust
              ? [
                  <dt>{t('profile.trust')}</dt>,
                  <dd>
                    {trustPill(r, d.meta.trustLevels)} {r.trust.locked ? <span className="MemberDesk-muted">{t('trust.locked')}</span> : null}{' '}
                    <a href={desk().profile(r.username) + '/trust'} target={desk().admin ? '_blank' : undefined} rel="noopener">
                      {t('trust.open')}
                    </a>
                  </dd>,
                ]
              : null}
            <dt>{t('profile.online_status')}</dt>
            <dd>{p.discloseOnline ? t('profile.online_shown') : t('profile.online_hidden')}</dd>
            {p.locale ? [<dt>{t('profile.locale')}</dt>, <dd>{p.locale}</dd>] : null}
          </dl>
        </section>
        <section className="MemberDesk-card">
          <h3>
            {t('profile.about')} {p.masquerade ? <span className="MemberDesk-ext">fof/masquerade</span> : null}
          </h3>
          {p.bio ? <p className="MemberDesk-bio">{p.bio}</p> : null}
          {p.fields.length ? (
            <dl className="MemberDesk-kv">
              {p.fields.map((f: any) => [
                <dt>
                  {f.icon ? <Icon name={f.icon} /> : null} {f.name}
                </dt>,
                <dd>{f.value || <span className="MemberDesk-faint">{t('profile.empty_field')}</span>}</dd>,
              ])}
            </dl>
          ) : !p.bio ? (
            <p className="MemberDesk-faint">{p.masquerade ? t('profile.no_fields') : t('profile.no_masquerade')}</p>
          ) : null}
        </section>
      </div>
    );
  }

  groupsTab() {
    const d = this.data;
    const r = d.member;
    const canEdit = d.can.addGroup;
    const addedAt = (id: number) => d.log.find((e: any) => e.action === 'addGroup' && e.after?.group === id);

    return (
      <section className="MemberDesk-card">
        <h3>{t('groups.title')}</h3>
        <ul className="MemberDesk-switches">
          {this.groups().map((g) => {
            const on = r.groups.includes(g.id);
            const locked = !canEdit || (g.id === 1 && !d.can.editAdmin) || r.anonymized;
            const added = on ? addedAt(g.id) : null;
            return (
              <li>
                <span className="MemberDesk-switchLabel">
                  {groupChip(g)}
                  {added ? (
                    <span className="MemberDesk-muted">
                      {t('groups.added', { date: date(added.createdAt), by: added.actor?.displayName || '—' })}
                    </span>
                  ) : null}
                </span>
                <button
                  type="button"
                  role="switch"
                  aria-checked={on ? 'true' : 'false'}
                  aria-label={ts('groups.toggle', { name: g.name })}
                  className={`MemberDesk-switch${on ? ' is-on' : ''}`}
                  disabled={locked || this.busy === `g${g.id}`}
                  onclick={() => this.toggleGroup(g.id, on)}
                />
              </li>
            );
          })}
        </ul>
        <p className="helpText">{t('groups.help')}</p>
      </section>
    );
  }

  activityTab() {
    const a = this.data.activity;
    const months = a.months as { month: string; posts: number }[];
    const peak = Math.max(1, ...months.map((x) => x.posts));
    const sessions = this.data.sessions;

    return (
      <div className="MemberDesk-grid">
        <section className="MemberDesk-card MemberDesk-card--wide">
          <h3>{t('activity.chart')}</h3>
          <div className="MemberDesk-chart" role="img" aria-label={ts('activity.chart_label')}>
            {months.map((x) => (
              <div className="MemberDesk-chartCol">
                <span className="MemberDesk-chartVal">{x.posts || ''}</span>
                <span className="MemberDesk-chartBar" style={{ '--h': `${Math.round((x.posts / peak) * 100)}%` }} />
                <span className="MemberDesk-chartLbl">{dayjs(x.month + '-01').format('MMM')}</span>
              </div>
            ))}
          </div>
          {a.likes ? <p className="MemberDesk-muted">{t('activity.likes', { given: num(a.likes.given), received: num(a.likes.received) })}</p> : null}
        </section>
        <section className="MemberDesk-card">
          <h3>{t('activity.posts')}</h3>
          {a.posts.length ? (
            <ul className="MemberDesk-list">
              {a.posts.map((p: any) => (
                <li>
                  <a href={desk().discussion(p.discussionId, p.slug, p.number)} target={desk().admin ? '_blank' : undefined} rel="noopener">
                    {p.discussion}
                  </a>
                  <span className="MemberDesk-excerpt">{p.excerpt}</span>
                  <span className="MemberDesk-muted">{ago(p.createdAt)}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="MemberDesk-faint">{t('activity.no_posts')}</p>
          )}
        </section>
        <section className="MemberDesk-card">
          <h3>{t('activity.discussions')}</h3>
          {a.discussions.length ? (
            <ul className="MemberDesk-list">
              {a.discussions.map((x: any) => (
                <li>
                  <a href={desk().discussion(x.id, x.slug)} target={desk().admin ? '_blank' : undefined} rel="noopener">
                    {x.title}
                  </a>
                  <span className="MemberDesk-muted">
                    {t('activity.replies', { count: num(x.replies) })} · {ago(x.createdAt)}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="MemberDesk-faint">{t('activity.no_discussions')}</p>
          )}
        </section>
        <section className="MemberDesk-card MemberDesk-card--wide">
          <h3>{t('activity.sessions')}</h3>
          {sessions.length ? (
            <ul className="MemberDesk-list">
              {sessions.map((s: any) => (
                <li className="MemberDesk-session">
                  <span>
                    <Icon name={/iPhone|Android/.test(s.agent) ? 'fas fa-mobile-screen' : 'fas fa-desktop'} />{' '}
                    {s.agent || t('activity.unknown_device')}
                    {s.ip ? <span className="MemberDesk-mono MemberDesk-muted"> · {s.ip}</span> : null}
                  </span>
                  <span className="MemberDesk-muted">{t('activity.last_used', { ago: ago(s.lastActivityAt || s.createdAt) })}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="MemberDesk-faint">{t('activity.no_sessions')}</p>
          )}
          {sessions.length && this.data.can.signOut ? (
            <Button className="Button" icon="fas fa-right-from-bracket" onclick={() => this.confirmAction('signOut')}>
              {t('member.sign_out')}
            </Button>
          ) : null}
        </section>
      </div>
    );
  }

  reportsTab() {
    const r = this.data.reports;
    const docket = r.source === 'docket';
    const base = app.forum.attribute<string>('baseUrl');
    const link = (x: any) =>
      docket && (x.id || x.reportId)
        ? `${base}/reports/${x.id || x.reportId}`
        : x.discussionId
          ? desk().discussion(x.discussionId, x.slug, x.number)
          : null;
    const target = desk().admin ? '_blank' : undefined;
    const status = (st: string | null) =>
      st ? (
        <span
          className={`MemberDesk-pill MemberDesk-pill--${{ open: 'warn', in_progress: 'info', resolved: 'ok', rejected: 'muted' }[st] || 'muted'}`}
        >
          {t(`reports.status_${st}`)}
        </span>
      ) : null;

    return (
      <div className="MemberDesk-grid">
        <section className="MemberDesk-card MemberDesk-card--wide">
          <h3>
            {t('reports.against_title', { count: r.total })} <span className="MemberDesk-ext">{docket ? 'ernestdefoe/docket' : 'flarum/flags'}</span>
          </h3>
          {!docket ? <p className="helpText">{t('reports.flags_only')}</p> : null}
          {r.against.length ? (
            <ul className="MemberDesk-list">
              {r.against.map((x: any) => {
                const href = link(x);
                return (
                  <li>
                    <span className="MemberDesk-reportHead">
                      {status(x.status)}
                      {href ? (
                        <a href={href} target={target} rel="noopener">
                          {x.discussion || t('reports.no_post')}
                        </a>
                      ) : (
                        <span>{x.discussion || t('reports.no_post')}</span>
                      )}
                    </span>
                    <span className="MemberDesk-muted">
                      {[
                        x.reports ? ts('reports.reporters', { count: x.reports }) : x.reason ? ts('reports.reason', { reason: x.reason }) : null,
                        x.assignee ? ts('reports.assignee', { name: x.assignee }) : null,
                        ago(x.createdAt),
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                    {x.outcome ? <span className="MemberDesk-excerpt">{t('reports.outcome', { outcome: x.outcome })}</span> : null}
                  </li>
                );
              })}
            </ul>
          ) : (
            <p className="MemberDesk-faint">{t('reports.none_against')}</p>
          )}
        </section>
        <section className="MemberDesk-card MemberDesk-card--wide">
          <h3>{t('reports.filed_title')}</h3>
          {r.filed.length ? (
            <ul className="MemberDesk-list">
              {r.filed.map((x: any) => {
                const href = link(x);
                return (
                  <li>
                    <span className="MemberDesk-reportHead">
                      {status(x.status)}
                      {href ? (
                        <a href={href} target={target} rel="noopener">
                          {x.discussion || t('reports.no_post')}
                        </a>
                      ) : (
                        <span>{x.discussion || t('reports.no_post')}</span>
                      )}
                    </span>
                    <span className="MemberDesk-muted">
                      {[
                        x.about ? ts('reports.about', { name: x.about }) : null,
                        x.reason ? ts('reports.reason', { reason: x.reason }) : null,
                        ago(x.createdAt),
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                  </li>
                );
              })}
            </ul>
          ) : (
            <p className="MemberDesk-faint">{t('reports.none_filed')}</p>
          )}
        </section>
      </div>
    );
  }

  ipsTab() {
    const ips = this.data.ips || [];
    const canBan = this.data.can.banIp;
    if (!ips.length)
      return (
        <section className="MemberDesk-card">
          <p className="MemberDesk-faint">{t('ips.none')}</p>
        </section>
      );

    return (
      <section className="MemberDesk-card">
        <h3>{t('ips.title')}</h3>
        <ul className="MemberDesk-ips">
          {ips.map((ip: any) => (
            <li className="MemberDesk-ip">
              <div className="MemberDesk-ipHead">
                <span className="MemberDesk-mono MemberDesk-ipAddr">{ip.ip}</span>
                <span className="MemberDesk-muted">
                  {t('ips.seen', {
                    sources: ip.sources.map((x: string) => ts(`ips.source_${x}`)).join(' + '),
                    count: num(ip.count),
                    first: date(ip.firstSeen),
                    last: ago(ip.lastSeen),
                  })}
                </span>
                {ip.banned ? (
                  <span className="MemberDesk-pill MemberDesk-pill--danger">{t('ips.banned')}</span>
                ) : canBan ? (
                  <Button
                    className="Button Button--small"
                    icon="fas fa-gavel"
                    loading={this.busy === `ban${ip.ip}`}
                    onclick={() => this.banIp(ip.ip)}
                  >
                    {t('ips.ban')}
                  </Button>
                ) : null}
              </div>
              {ip.others.length ? (
                <div className="MemberDesk-others">
                  <span className="MemberDesk-othersLabel">
                    <Icon name="fas fa-user-group" /> {t('ips.others', { count: ip.others.length })}
                  </span>
                  {ip.others.map((o: any) => (
                    <a className="MemberDesk-other" href={desk().member(o.id)} onclick={(e: MouseEvent) => this.go(e, desk().member(o.id))}>
                      {avatar(o, 'sm')}
                      <span>{o.displayName}</span>
                    </a>
                  ))}
                </div>
              ) : (
                <span className="MemberDesk-faint">{t('ips.no_others')}</span>
              )}
            </li>
          ))}
        </ul>
      </section>
    );
  }

  notesTab() {
    const n = this.data.notes;
    if (!n.allowed)
      return (
        <section className="MemberDesk-card">
          <p className="MemberDesk-faint">{t('notes.no_permission')}</p>
        </section>
      );
    return (
      <section className="MemberDesk-card">
        <h3>
          {t('notes.title')} <span className="MemberDesk-ext">fof/moderator-notes</span>
        </h3>
        {n.items.length ? (
          <ul className="MemberDesk-notes">
            {n.items.map((x: any) => (
              <li>
                <p>{x.note}</p>
                <span className="MemberDesk-muted">
                  {x.by || '—'} · {ago(x.createdAt)}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="MemberDesk-faint">{t('notes.none')}</p>
        )}
        {n.canCreate ? (
          <form className="MemberDesk-noteForm" onsubmit={(e: SubmitEvent) => this.addNote(e)}>
            <label for="md-note" className="visually-hidden">
              {t('notes.add_label')}
            </label>
            <textarea
              id="md-note"
              className="FormControl"
              rows={3}
              placeholder={ts('notes.placeholder')}
              value={this.noteText}
              oninput={(e: InputEvent) => (this.noteText = (e.target as HTMLTextAreaElement).value)}
            />
            <Button type="submit" className="Button Button--primary" loading={this.noteSaving} disabled={!this.noteText.trim()}>
              {t('notes.add')}
            </Button>
          </form>
        ) : null}
      </section>
    );
  }

  warningsTab() {
    const d = this.data;
    const w = d.warnings;
    const history = d.log.filter((e: any) => ['suspend', 'unsuspend', 'spammer', 'unspam'].includes(e.action));
    return (
      <div className="MemberDesk-grid">
        {d.suspension ? (
          <section className="MemberDesk-card">
            <h3>{t('warnings.suspension')}</h3>
            {d.member.suspendedUntil ? (
              <dl className="MemberDesk-kv">
                <dt>{t('warnings.until')}</dt>
                <dd>{isForever(d.member.suspendedUntil) ? t('warnings.forever') : date(d.member.suspendedUntil)}</dd>
                <dt>{t('warnings.reason')}</dt>
                <dd>{d.suspension.reason || <span className="MemberDesk-faint">—</span>}</dd>
                <dt>{t('warnings.message')}</dt>
                <dd>{d.suspension.message || <span className="MemberDesk-faint">—</span>}</dd>
              </dl>
            ) : (
              <p className="MemberDesk-faint">{t('warnings.not_suspended')}</p>
            )}
          </section>
        ) : null}
        {w ? (
          <section className="MemberDesk-card">
            <h3>
              {t('warnings.title')} <span className="MemberDesk-ext">fof/moderator-warnings</span>
            </h3>
            {!w.allowed ? (
              <p className="MemberDesk-faint">{t('warnings.no_permission')}</p>
            ) : w.items.length ? (
              [
                <p className="MemberDesk-strikes">{t('warnings.strikes', { count: num(w.strikes) })}</p>,
                <ul className="MemberDesk-list">
                  {w.items.map((x: any) => (
                    <li className={x.hidden ? 'is-hidden' : ''}>
                      <span>
                        <b>{t('warnings.strike_n', { count: x.strikes })}</b> {x.comment}
                      </span>
                      <span className="MemberDesk-muted">
                        {x.by || '—'} · {ago(x.createdAt)}
                      </span>
                    </li>
                  ))}
                </ul>,
              ]
            ) : (
              <p className="MemberDesk-faint">{t('warnings.none')}</p>
            )}
          </section>
        ) : null}
        <section className="MemberDesk-card MemberDesk-card--wide">
          <h3>{t('warnings.history')}</h3>
          {history.length ? this.timeline(history) : <p className="MemberDesk-faint">{t('warnings.no_history')}</p>}
        </section>
      </div>
    );
  }

  logTab() {
    const log = this.data.log;
    return (
      <section className="MemberDesk-card">
        <h3>{t('log.member_title')}</h3>
        {log.length ? this.timeline(log) : <p className="MemberDesk-faint">{t('log.none')}</p>}
      </section>
    );
  }

  timeline(entries: any[]): Mithril.Children {
    return (
      <ol className="MemberDesk-timeline">
        {entries.map((e) => (
          <li className={`MemberDesk-event MemberDesk-event--${e.action}`}>
            <span className="MemberDesk-eventDot" aria-hidden="true" />
            <div>
              <b>{t(`action.${e.action}`)}</b> {describe(e, (id) => this.group(id))}
              {e.status !== 'done' ? <span className="MemberDesk-pill MemberDesk-pill--warn">{t(`log.status_${e.status}`)}</span> : null}
              <div className="MemberDesk-muted">{t('log.by', { actor: e.actor?.displayName || ts('log.system'), ago: ago(e.createdAt) })}</div>
            </div>
          </li>
        ))}
      </ol>
    );
  }

  // --- actions

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

/** One line saying what a log entry changed. */
export function describe(e: any, group: (id: number) => GroupInfo | undefined): Mithril.Children {
  const a = e.after || {};
  const b = e.before || {};
  switch (e.action) {
    case 'addGroup':
    case 'removeGroup':
      return a.group == null ? ts('log.hidden_group') : group(a.group)?.name || `#${a.group}`;
    case 'suspend':
      return a.until
        ? (isForever(a.until) ? ts('log.forever') : ts('log.until', { date: date(a.until) })) + (a.reason ? ` · “${a.reason}”` : '')
        : '';
    case 'spammer':
      return (
        ts('log.spammer', { posts: num(a.posts || 0), discussions: num(a.discussions || 0), ips: num(a.bannedIps || 0) }) +
        (a.restored ? ` · ${ts('log.restored')}` : '')
      );
    case 'anonymize':
    case 'delete':
      return [
        b.username ? ts('log.was', { name: b.username }) : '',
        a.via === 'gdpr' ? ' · flarum/gdpr' : '',
        e.hashesKept ? ` · ${ts('log.hashes')}` : '',
        b.pruned ? ` · ${ts('log.pruned')}` : '',
      ].join('');
    case 'export':
      return ts('log.rows', { count: num(a.rows || 0) });
    case 'signOut':
      return ts('log.sessions', { count: num(b.sessions || 0) });
    default:
      return '';
  }
}
