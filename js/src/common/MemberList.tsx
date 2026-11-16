import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { deskState, type SavedFilter } from './DeskState';
import ConfirmModal, { reportRun } from './ConfirmModal';
import SaveFilterModal from './SaveFilterModal';
import { ago, avatar, date, desk, groupChip, jsonApi, num, savedFilters, statusPills, t, trustPill, ts, type Row } from './util';

const STATUSES: [string, string | null][] = [
  ['unconfirmed', null],
  ['suspended', 'suspend'],
  ['anonymized', 'gdpr'],
  ['noposts', null],
  ['banned', 'banIps'],
  ['reported', 'reports'],
  ['silenced', 'trust'],
];

const BULK = ['addGroup', 'removeGroup', 'suspend', 'confirm', 'spammer', 'anonymize', 'delete'];

/**
 * The members list: filters on the left (a sheet on phones), the table (cards on phones), and a
 * bulk bar once anything is selected. Shared by the admin's Users page and the forum's
 * /member-desk for moderators.
 */
export default class MemberList extends Component {
  filtersOpen = false;
  searchTimer: any = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const s = deskState();
    if (!s.meta) s.load();
  }

  onremove() {
    clearTimeout(this.searchTimer);
  }

  view() {
    const s = deskState();
    const meta = s.meta;

    if (!meta && s.error)
      return (
        <div className="MemberDesk">
          <p className="MemberDesk-error">{s.error}</p>
        </div>
      );
    if (!meta)
      return (
        <div className="MemberDesk">
          <LoadingIndicator display="block" />
        </div>
      );

    return (
      <div className="MemberDesk MemberDesk--list">
        <div className="MemberDesk-bar">
          <nav className="MemberDesk-tabs" aria-label={ts('list.views')}>
            <a className="is-active" aria-current="page" href={desk().list()}>
              {t('list.tab_members')}
            </a>
            <a href={desk().log()} onclick={(e: MouseEvent) => this.nav(e, desk().log())}>
              {t('list.tab_log')}
            </a>
          </nav>
          <div className="MemberDesk-barActions">
            {meta.can.export ? (
              <Button className="Button" icon="fas fa-file-csv" onclick={() => this.exportCsv()}>
                {t('list.export')}
              </Button>
            ) : null}
          </div>
        </div>

        <div className="MemberDesk-scope">
          <div className="MemberDesk-layout">
            {this.filters()}
            <section className="MemberDesk-main" aria-busy={s.loading ? 'true' : 'false'}>
              {this.toolbar()}
              {this.chips()}
              {this.selectBanner()}
              {this.table()}
              {this.cards()}
              {this.pager()}
            </section>
          </div>
        </div>

        {this.bulkBar()}
      </div>
    );
  }

  /** A date filter, ignoring the half-typed years a date field reports while someone types (0002, 0202). */
  date(key: 'joinedFrom' | 'joinedTo' | 'seenFrom' | 'seenTo', value: string) {
    if (value !== '' && Number(value.slice(0, 4)) < 1990) return;
    deskState().setFilter(key, value);
  }

  nav(e: MouseEvent, href: string) {
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
    e.preventDefault();
    m.route.set(this.routePath(href));
  }

  open(e: MouseEvent, r: Row) {
    const target = e.target as HTMLElement;
    if (target.closest('input, a, button, label')) return;
    const href = desk().member(r.id);
    if (e.ctrlKey || e.metaKey) {
      window.open(href, '_blank');
      return;
    }
    m.route.set(this.routePath(href));
  }

  routePath(href: string): string {
    // Admin routes are hash routes (#/users/5); forum routes are paths.
    const i = href.indexOf('#');
    if (i >= 0) return href.slice(i + 1);
    try {
      return new URL(href, window.location.href).pathname.replace(new URL(app.forum.attribute('baseUrl')).pathname.replace(/\/$/, ''), '') || '/';
    } catch {
      return href;
    }
  }

  filters(): Mithril.Children {
    const s = deskState();
    const meta = s.meta!;
    const ext = meta.extensions;
    const statuses = STATUSES.filter(
      ([, need]) => !need || (need === 'reports' ? !!meta.reports : need === 'trust' ? !!meta.trustLevels : ext[need])
    ).filter(([k]) => k !== 'banned' || meta.can.ips);

    return (
      <aside className={`MemberDesk-filters${this.filtersOpen ? ' is-open' : ''}`} aria-label={ts('filters.label')}>
        {this.savedFilters()}

        <fieldset className="MemberDesk-fgroup">
          <legend>{t('filters.groups')}</legend>
          {meta.groups.map((g) => this.check('groups', String(g.id), groupChip(g)))}
          {this.check('groups', 'none', t('filters.no_group'))}
        </fieldset>

        <fieldset className="MemberDesk-fgroup">
          <legend>{t('filters.status')}</legend>
          {statuses.map(([k]) => this.check('status', k, t(`filters.status_${k}`)))}
        </fieldset>

        <fieldset className="MemberDesk-fgroup">
          <legend>{t('filters.joined')}</legend>
          {this.presets('joinedWithin', ['P1D', 'P7D', 'P30D', 'P1Y'], (p) => t(`filters.within_${p}`))}
          {this.dateRange('joinedFrom', 'joinedTo')}
        </fieldset>

        {meta.can.lastSeen ? (
          <fieldset className="MemberDesk-fgroup">
            <legend>{t('filters.seen')}</legend>
            {this.presets('notSeenFor', ['P30D', 'P6M', 'P1Y', 'P2Y'], (p) => t(`filters.away_${p}`))}
            {this.dateRange('seenFrom', 'seenTo')}
          </fieldset>
        ) : null}

        {meta.trustLevels ? (
          <fieldset className="MemberDesk-fgroup">
            <legend>{t('filters.trust')}</legend>
            {meta.trustLevels.map((l) => this.check('trust', String(l.level), t('trust.pill', { level: l.level, name: l.name })))}
          </fieldset>
        ) : null}

        {meta.reports ? (
          <fieldset className="MemberDesk-fgroup">
            <legend>{t('filters.reported')}</legend>
            <div className="MemberDesk-range">{this.numberInput('reportedMin', t('filters.reported_min'), 1)}</div>
            {this.presets(
              'reportedWithin',
              ['P7D', 'P30D', 'P90D', ''],
              (p) => t(`filters.reported_within_${p || 'ever'}`),
              'MemberDesk-presets--after',
              false
            )}
          </fieldset>
        ) : null}

        <fieldset className="MemberDesk-fgroup">
          <legend>{t('filters.posts')}</legend>
          <div className="MemberDesk-range">
            {this.numberInput('minPosts', t('filters.min'), 0)}
            {this.numberInput('maxPosts', t('filters.max'), 0)}
          </div>
        </fieldset>

        {this.filtersOpen ? (
          <Button className="Button Button--primary MemberDesk-filtersDone" onclick={() => (this.filtersOpen = false)}>
            {t('filters.show', { count: num(s.total) })}
          </Button>
        ) : null}
      </aside>
    );
  }

  savedFilters(): Mithril.Children {
    const s = deskState();

    return (
      <div className="MemberDesk-fgroup MemberDesk-saved">
        <h4>{t('filters.saved')}</h4>
        {s.meta!.filters.map((sf) => (
          <div className={`MemberDesk-savedItem${s.activeFilter === sf.id ? ' is-active' : ''}`}>
            <button type="button" className="MemberDesk-savedApply" onclick={() => s.apply(sf)}>
              <span>{sf.name}</span>
              <span className="MemberDesk-muted">{sf.shared ? t('filters.shared') : t('filters.mine')}</span>
            </button>
            {sf.canEdit ? (
              <button
                type="button"
                className="MemberDesk-savedDelete"
                aria-label={ts('filters.delete', { name: sf.name })}
                title={ts('filters.delete', { name: sf.name })}
                onclick={() => this.deleteFilter(sf.id)}
              >
                <Icon name="fas fa-xmark" />
              </button>
            ) : null}
          </div>
        ))}
        <Button className="Button Button--link MemberDesk-saveCurrent" icon="fas fa-plus" onclick={() => this.saveFilter()}>
          {t('filters.save')}
        </Button>
      </div>
    );
  }

  /** One checkbox in a list filter (groups, status, trust). */
  check(key: 'groups' | 'status' | 'trust', value: string, label: Mithril.Children): Mithril.Children {
    const s = deskState();

    return (
      <label className="MemberDesk-check">
        <input type="checkbox" checked={s.filters[key].includes(value)} onchange={() => s.toggleIn(key, value)} />
        {label}
      </label>
    );
  }

  /** Quick-pick buttons; a second click clears the pick unless `toggles` is false. */
  presets(
    key: 'joinedWithin' | 'notSeenFor' | 'reportedWithin',
    values: string[],
    label: (p: string) => Mithril.Children,
    className = '',
    toggles = true
  ): Mithril.Children {
    const s = deskState();
    const current = s.filters[key];

    return (
      <div className={`MemberDesk-presets ${className}`.trim()}>
        {values.map((p) => (
          <button
            type="button"
            className={`MemberDesk-preset${current === p ? ' is-on' : ''}`}
            aria-pressed={current === p ? 'true' : 'false'}
            onclick={() => s.setFilter(key, toggles && current === p ? '' : p)}
          >
            {label(p)}
          </button>
        ))}
      </div>
    );
  }

  dateRange(from: 'joinedFrom' | 'seenFrom', to: 'joinedTo' | 'seenTo'): Mithril.Children {
    const f = deskState().filters;
    const input = (key: typeof from | typeof to, label: Mithril.Children) => (
      <label>
        <span>{label}</span>
        <input type="date" className="FormControl" value={f[key]} onchange={(e: Event) => this.date(key, (e.target as HTMLInputElement).value)} />
      </label>
    );

    return (
      <div className="MemberDesk-range MemberDesk-range--dates">
        {input(from, t('filters.from'))}
        {input(to, t('filters.to'))}
      </div>
    );
  }

  numberInput(key: 'reportedMin' | 'minPosts' | 'maxPosts', label: Mithril.Children, min: number): Mithril.Children {
    const s = deskState();

    return (
      <label>
        <span>{label}</span>
        <input
          type="number"
          min={String(min)}
          inputmode="numeric"
          className="FormControl"
          value={s.filters[key]}
          onchange={(e: Event) => s.setFilter(key, (e.target as HTMLInputElement).value)}
        />
      </label>
    );
  }

  toolbar(): Mithril.Children {
    const s = deskState();
    const meta = s.meta!;
    const sorts = ['joinedAt', 'lastSeenAt', 'posts', 'discussions', 'username'].filter((k) => k !== 'lastSeenAt' || meta.can.lastSeen);
    const active = s.activeCount();

    return (
      <div className="MemberDesk-toolbar">
        <div className="MemberDesk-search">
          <Icon name="fas fa-magnifying-glass" />
          <input
            type="search"
            className="FormControl"
            aria-label={ts('list.search_label')}
            placeholder={ts(
              meta.can.ips ? (meta.can.emails ? 'list.search_all' : 'list.search_ip') : meta.can.emails ? 'list.search_email' : 'list.search_name'
            )}
            value={s.filters.q}
            oninput={(e: InputEvent) => {
              const v = (e.target as HTMLInputElement).value;
              s.filters.q = v;
              clearTimeout(this.searchTimer);
              this.searchTimer = setTimeout(() => s.setFilter('q', v), 300);
            }}
          />
        </div>
        <Button
          className="Button MemberDesk-filtersToggle"
          icon="fas fa-sliders"
          aria-expanded={this.filtersOpen ? 'true' : 'false'}
          onclick={() => (this.filtersOpen = !this.filtersOpen)}
        >
          {active ? t('list.filters_n', { count: active }) : t('list.filters')}
        </Button>
        <label className="MemberDesk-sort">
          <span className="visually-hidden">{t('list.sort')}</span>
          <select
            className="FormControl"
            value={`${s.sort}:${s.dir}`}
            onchange={(e: Event) => {
              const [k, d] = (e.target as HTMLSelectElement).value.split(':');
              s.sort = k;
              s.dir = d as any;
              s.page = 1;
              s.load();
            }}
          >
            {sorts.flatMap((k) => [
              <option value={`${k}:desc`}>{t(`sort.${k}_desc`)}</option>,
              <option value={`${k}:asc`}>{t(`sort.${k}_asc`)}</option>,
            ])}
          </select>
        </label>
      </div>
    );
  }

  chips(): Mithril.Children {
    const s = deskState();
    const f = s.filters;
    const chips: [string, Mithril.Children, () => void][] = [];
    f.groups.forEach((g) =>
      chips.push([
        `g${g}`,
        g === 'none' ? t('filters.no_group') : t('chips.group', { name: s.group(Number(g))?.name || g }),
        () => s.toggleIn('groups', g),
      ])
    );
    f.status.forEach((k) => chips.push([`s${k}`, t(`filters.status_${k}`), () => s.toggleIn('status', k)]));
    f.trust.forEach((l) =>
      chips.push([
        `t${l}`,
        t('trust.pill', { level: l, name: s.meta?.trustLevels?.find((x) => String(x.level) === l)?.name || '' }),
        () => s.toggleIn('trust', l),
      ])
    );
    if (f.joinedWithin) chips.push(['jw', t(`chips.joined_${f.joinedWithin}`), () => s.setFilter('joinedWithin', '')]);
    if (f.notSeenFor) chips.push(['ns', t(`chips.away_${f.notSeenFor}`), () => s.setFilter('notSeenFor', '')]);
    if (f.joinedFrom) chips.push(['jf', t('chips.joined_from', { date: date(f.joinedFrom) }), () => s.setFilter('joinedFrom', '')]);
    if (f.joinedTo) chips.push(['jt', t('chips.joined_to', { date: date(f.joinedTo) }), () => s.setFilter('joinedTo', '')]);
    if (f.seenFrom) chips.push(['sf', t('chips.seen_from', { date: date(f.seenFrom) }), () => s.setFilter('seenFrom', '')]);
    if (f.seenTo) chips.push(['st', t('chips.seen_to', { date: date(f.seenTo) }), () => s.setFilter('seenTo', '')]);
    if (f.minPosts !== '') chips.push(['mn', t('chips.min_posts', { count: f.minPosts }), () => s.setFilter('minPosts', '')]);
    if (f.maxPosts !== '') chips.push(['mx', t('chips.max_posts', { count: f.maxPosts }), () => s.setFilter('maxPosts', '')]);
    if (f.reportedMin !== '')
      chips.push([
        'rm',
        t(`chips.reported_${f.reportedWithin || 'ever'}`, { count: f.reportedMin }),
        () => {
          s.filters.reportedWithin = '';
          s.setFilter('reportedMin', '');
        },
      ]);

    return (
      <div className="MemberDesk-chips" aria-live="polite">
        <span className="MemberDesk-count">
          {s.loading ? <LoadingIndicator display="inline" size="small" /> : null}
          {t('list.count', { count: num(s.total) })}
        </span>
        {chips.map(([key, label, remove]) => (
          <span className="MemberDesk-chip" key={key}>
            {label}
            <button type="button" aria-label={ts('chips.remove')} onclick={remove}>
              <Icon name="fas fa-xmark" />
            </button>
          </span>
        ))}
        {chips.length ? (
          <button type="button" className="MemberDesk-clear" onclick={() => s.clear()}>
            {t('chips.clear')}
          </button>
        ) : null}
      </div>
    );
  }

  selectBanner(): Mithril.Children {
    const s = deskState();
    if (s.allMatching) {
      return (
        <div className="MemberDesk-selall" role="status">
          {t('list.all_selected', { count: num(s.total) })}{' '}
          <button type="button" onclick={() => s.clearSelection()}>
            {t('list.clear_selection')}
          </button>
        </div>
      );
    }
    if (s.pageSelected() && s.total > s.rows.length) {
      return (
        <div className="MemberDesk-selall" role="status">
          {t('list.page_selected', { count: num(s.rows.length) })}{' '}
          <button type="button" onclick={() => (s.allMatching = true)}>
            {t('list.select_all', { count: num(s.total) })}
          </button>
        </div>
      );
    }
    return null;
  }

  sortHeader(key: string | null, label: string, cls = ''): Mithril.Children {
    const s = deskState();
    if (!key)
      return (
        <th className={cls} scope="col">
          {label}
        </th>
      );
    const on = s.sort === key;
    return (
      <th className={cls} scope="col" aria-sort={on ? (s.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
        <button type="button" className="MemberDesk-sortBtn" onclick={() => s.sortBy(key)}>
          {label}
          <Icon name={on ? (s.dir === 'asc' ? 'fas fa-arrow-up' : 'fas fa-arrow-down') : 'fas fa-sort'} className={on ? '' : 'MemberDesk-sortIdle'} />
        </button>
      </th>
    );
  }

  table(): Mithril.Children {
    const s = deskState();
    const can = s.meta!.can;
    const sel = (r: Row) => s.allMatching || s.selected.has(r.id);

    return (
      <div className="MemberDesk-tableWrap">
        <table className="MemberDesk-table">
          <thead>
            <tr>
              <th className="MemberDesk-cb" scope="col">
                <input type="checkbox" aria-label={ts('list.select_page')} checked={s.pageSelected()} onchange={() => s.togglePage()} />
              </th>
              {this.sortHeader('username', ts('col.member'))}
              {this.sortHeader(can.emails ? 'email' : null, ts('col.email'))}
              {this.sortHeader(null, ts('col.groups'))}
              {this.sortHeader('joinedAt', ts('col.joined'), 'MemberDesk-colMid')}
              {this.sortHeader(can.lastSeen ? 'lastSeenAt' : null, ts('col.seen'))}
              {this.sortHeader('posts', ts('col.posts'), 'is-num')}
              {this.sortHeader('discussions', ts('col.discussions'), 'is-num MemberDesk-colWide')}
              {s.meta!.reports ? this.sortHeader(null, ts('col.reports'), 'is-num') : null}
              {can.ips ? this.sortHeader(null, ts('col.ip'), 'MemberDesk-colWide') : null}
              {this.sortHeader(null, ts('col.status'))}
            </tr>
          </thead>
          <tbody>
            {s.rows.map((r) => (
              <tr key={r.id} className={sel(r) ? 'is-selected' : ''} onclick={(e: MouseEvent) => this.open(e, r)}>
                <td className="MemberDesk-cb">
                  <input
                    type="checkbox"
                    aria-label={ts('list.select_one', { name: r.displayName })}
                    checked={sel(r)}
                    onchange={() => s.toggle(r.id)}
                  />
                </td>
                <td>
                  <a className="MemberDesk-who" href={desk().member(r.id)} onclick={(e: MouseEvent) => this.nav(e, desk().member(r.id))}>
                    {avatar(r, 'sm')}
                    <span className="MemberDesk-names">
                      <span className="MemberDesk-name">{r.displayName}</span>
                      <span className="MemberDesk-username">@{r.username}</span>
                    </span>
                  </a>
                </td>
                <td className="MemberDesk-muted MemberDesk-email">
                  {r.email}
                  {r.confirmed ? null : <Icon name="far fa-envelope" className="MemberDesk-unverifiedIcon" aria-label={ts('status.unconfirmed')} />}
                </td>
                <td className="MemberDesk-groups">{this.groupList(r)}</td>
                <td className="MemberDesk-muted MemberDesk-colMid" title={r.joinedAt || ''}>
                  {date(r.joinedAt)}
                </td>
                <td className="MemberDesk-muted" title={r.lastSeenAt || ''}>
                  {r.lastSeenAt ? ago(r.lastSeenAt) : <span className="MemberDesk-faint">{t('list.hidden')}</span>}
                </td>
                <td className="is-num">{num(r.posts)}</td>
                <td className="is-num MemberDesk-colWide">{num(r.discussions)}</td>
                {s.meta!.reports ? <td className="is-num">{this.reportCell(r)}</td> : null}
                {can.ips ? <td className="MemberDesk-mono MemberDesk-muted MemberDesk-colWide">{r.lastIp || '—'}</td> : null}
                <td className="MemberDesk-status">
                  {statusPills(r, true)}
                  {trustPill(r, s.meta?.trustLevels, true)}
                </td>
              </tr>
            ))}
            {!s.rows.length && !s.loading ? (
              <tr>
                <td colSpan={11} className="MemberDesk-empty">
                  {t('list.empty')}
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    );
  }

  reportCell(r: Row): Mithril.Children {
    if (!r.reports || !r.reports.total) return <span className="MemberDesk-faint">0</span>;
    const label = ts('list.reports_title', { total: r.reports.total, open: r.reports.open });
    return (
      <span className={`MemberDesk-reports${r.reports.open ? ' is-open' : ''}`} title={label} aria-label={label}>
        {r.reports.open ? <Icon name="fas fa-flag" /> : null}
        {num(r.reports.total)}
        {r.reports.open && r.reports.open !== r.reports.total ? <span className="MemberDesk-reportsOpen">{r.reports.open}</span> : null}
      </span>
    );
  }

  groupList(r: Row): Mithril.Children {
    const s = deskState();
    if (!r.groups.length) return <span className="MemberDesk-faint">{t('list.members_only')}</span>;
    const shown = r.groups.slice(0, 2).map((id) => groupChip(s.group(id)));
    return [shown, r.groups.length > 2 ? <span className="MemberDesk-more">+{r.groups.length - 2}</span> : null];
  }

  cards(): Mithril.Children {
    const s = deskState();
    const sel = (r: Row) => s.allMatching || s.selected.has(r.id);
    return (
      <ul className="MemberDesk-cards">
        <li className="MemberDesk-cardsHead">
          <label>
            <input type="checkbox" checked={s.pageSelected()} onchange={() => s.togglePage()} /> {t('list.select_page')}
          </label>
        </li>
        {s.rows.map((r) => (
          <li key={r.id} className={`MemberDesk-card${sel(r) ? ' is-selected' : ''}`} onclick={(e: MouseEvent) => this.open(e, r)}>
            <input type="checkbox" aria-label={ts('list.select_one', { name: r.displayName })} checked={sel(r)} onchange={() => s.toggle(r.id)} />
            {avatar(r, 'md')}
            <div className="MemberDesk-cardBody">
              <a className="MemberDesk-name" href={desk().member(r.id)} onclick={(e: MouseEvent) => this.nav(e, desk().member(r.id))}>
                {r.displayName}
              </a>
              <span className="MemberDesk-username">
                @{r.username} · {r.email}
              </span>
              <div className="MemberDesk-cardPills">
                {this.groupList(r)} {statusPills(r, true)} {trustPill(r, s.meta?.trustLevels, true)}
              </div>
              <div className="MemberDesk-cardStrip">
                <span>{t('list.card_posts', { count: num(r.posts) })}</span>
                <span>{t('list.card_joined', { date: date(r.joinedAt) })}</span>
                {r.lastSeenAt ? <span>{t('list.card_seen', { ago: ago(r.lastSeenAt) })}</span> : null}
              </div>
            </div>
          </li>
        ))}
        {!s.rows.length && !s.loading ? <li className="MemberDesk-empty">{t('list.empty')}</li> : null}
      </ul>
    );
  }

  pager(): Mithril.Children {
    const s = deskState();
    const pages = s.pages();
    return (
      <nav className="MemberDesk-pager" aria-label={ts('list.pages')}>
        <span className="MemberDesk-muted">{t('list.page_of', { page: s.page, pages })}</span>
        <span className="MemberDesk-pagerBtns">
          <Button className="Button" icon="fas fa-chevron-left" disabled={s.page <= 1} onclick={() => s.goto(s.page - 1)}>
            {t('list.prev')}
          </Button>
          <Button className="Button" disabled={s.page >= pages} onclick={() => s.goto(s.page + 1)}>
            {t('list.next')} <Icon name="fas fa-chevron-right" />
          </Button>
        </span>
      </nav>
    );
  }

  bulkBar(): Mithril.Children {
    const s = deskState();
    const count = s.selectionCount();
    if (!count) return null;
    const can = s.meta!.can;
    const actions = BULK.filter((a) => (a === 'delete' ? can.delete || can.anonymize : can[a]));

    return (
      <div className="MemberDesk-bulk" role="region" aria-label={ts('bulk.label')}>
        <span className="MemberDesk-bulkCount">{t('bulk.selected', { count: num(count) })}</span>
        <div className="MemberDesk-bulkActions">
          {actions.map((a) => (
            <Button
              className={`Button Button--small${['spammer', 'anonymize', 'delete'].includes(a) ? ' MemberDesk-bulkDanger' : ''}`}
              onclick={() => this.bulk(a)}
            >
              {t(`bulk.${a}`)}
            </Button>
          ))}
          {can.export ? (
            <Button className="Button Button--small" onclick={() => this.exportCsv(true)}>
              {t('bulk.export')}
            </Button>
          ) : null}
        </div>
        <Button
          className="Button Button--icon Button--small MemberDesk-bulkClose"
          icon="fas fa-xmark"
          aria-label={ts('bulk.clear')}
          onclick={() => s.clearSelection()}
        />
      </div>
    );
  }

  bulk(action: string) {
    const s = deskState();
    app.modal.show(ConfirmModal, {
      action,
      target: s.target(),
      groups: s.meta!.groups,
      gdprDeletionOff: s.meta!.gdprDeletionOff,
      onDone: (res: any) =>
        reportRun(res, () => {
          s.clearSelection();
          s.load();
        }),
    });
  }

  exportCsv(selection = false) {
    const s = deskState();
    const params: Record<string, any> =
      selection && !s.allMatching ? { ids: Array.from(s.selected).join(','), sort: s.sort, dir: s.dir } : s.filterParams();
    // A one-time value per click: the server logs each click once, even when the browser fetches the file twice.
    const qs = m.buildQueryString({ ...params, dl: Date.now().toString(36) + Math.random().toString(36).slice(2, 8) });
    window.location.href = `${app.forum.attribute('apiUrl')}/member-desk/export?${qs}`;
  }

  saveFilter() {
    const s = deskState();
    app.modal.show(SaveFilterModal, {
      isAdmin: !!s.meta!.can.isAdmin,
      query: { ...s.filterParams() },
      onSaved: (filters: any) => {
        s.meta!.filters = filters;
        m.redraw();
      },
    });
  }

  deleteFilter(id: number) {
    const s = deskState();
    const f = s.meta!.filters.find((x) => x.id === id);
    if (!f || !confirm(ts('filters.delete_confirm', { name: f.name }))) return;
    jsonApi('DELETE', `member-desk-filters/${id}`)
      .then(() => savedFilters<SavedFilter>())
      .then((filters) => {
        s.meta!.filters = filters;
        if (s.activeFilter === id) s.activeFilter = null;
        m.redraw();
      });
  }
}
