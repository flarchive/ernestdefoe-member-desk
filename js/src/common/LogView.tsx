import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { deskState } from './DeskState';
import { describe } from './MemberView';
import { ago, api, avatar, date, desk, errorText, num, t, ts } from './util';

const ACTIONS = [
  'addGroup',
  'removeGroup',
  'suspend',
  'unsuspend',
  'confirm',
  'signOut',
  'spammer',
  'unspam',
  'anonymize',
  'delete',
  'export',
  'banIp',
  'hashCheck',
];

/**
 * Every Member Desk action: who did it, to whom, when, and what changed. Erased members show as
 * they were (names until the retention period; email and IPs only ever as hashes). Admins can
 * check an address against those hashes.
 */
export default class LogView extends Component {
  rows: any[] = [];
  total = 0;
  page = 1;
  action = '';
  loading = true;
  error: string | null = null;
  retention = 90;
  check = '';
  checking = false;
  matches: any[] | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    if (!deskState().meta) deskState().load();
    this.load();
  }

  load() {
    this.loading = true;
    api('GET', '/log', undefined, { page: this.page, action: this.action || undefined })
      .then((res: any) => {
        this.rows = res.rows;
        this.total = res.total;
        this.retention = res.retentionDays;
      })
      .catch((e) => (this.error = errorText(e)))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  go(e: MouseEvent, href: string) {
    if (e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    const i = href.indexOf('#');
    if (i >= 0) return m.route.set(href.slice(i + 1));
    const base = new URL(app.forum.attribute('baseUrl')).pathname.replace(/\/$/, '');
    m.route.set(new URL(href, window.location.href).pathname.replace(base, '') || '/');
  }

  view() {
    const meta = deskState().meta;
    const group = (id: number) => meta?.groups.find((g) => g.id === id);
    const pages = Math.max(1, Math.ceil(this.total / 50));

    return (
      <div className="MemberDesk MemberDesk--log">
        <div className="MemberDesk-bar">
          <nav className="MemberDesk-tabs" aria-label={ts('list.views')}>
            <a href={desk().list()} onclick={(e: MouseEvent) => this.go(e, desk().list())}>
              {t('list.tab_members')}
            </a>
            <a className="is-active" aria-current="page" href={desk().log()}>
              {t('list.tab_log')}
            </a>
          </nav>
        </div>

        <div className="MemberDesk-toolbar">
          <label className="MemberDesk-sort">
            <span className="visually-hidden">{t('log.filter_action')}</span>
            <select
              className="FormControl"
              value={this.action}
              onchange={(e: Event) => {
                this.action = (e.target as HTMLSelectElement).value;
                this.page = 1;
                this.load();
              }}
            >
              <option value="">{t('log.all_actions')}</option>
              {ACTIONS.map((a) => (
                <option value={a}>{t(`action.${a}`)}</option>
              ))}
            </select>
          </label>
          <span className="MemberDesk-muted">{t('log.retention', { days: this.retention })}</span>
          {meta?.extensions.audit ? <span className="MemberDesk-muted">{t('log.mirrored')}</span> : null}
        </div>

        {meta?.can.isAdmin ? (
          <form className="MemberDesk-hashCheck" onsubmit={(e: SubmitEvent) => this.runCheck(e)}>
            <label for="md-hash">{t('log.check_label')}</label>
            <div className="MemberDesk-hashRow">
              <input
                id="md-hash"
                className="FormControl"
                placeholder={ts('log.check_placeholder')}
                value={this.check}
                oninput={(e: InputEvent) => (this.check = (e.target as HTMLInputElement).value)}
              />
              <Button type="submit" className="Button" loading={this.checking} disabled={!this.check.trim()}>
                {t('log.check')}
              </Button>
            </div>
            {this.matches ? (
              <p className={this.matches.length ? 'MemberDesk-callout MemberDesk-callout--info' : 'MemberDesk-muted'}>
                {this.matches.length
                  ? t('log.check_found', {
                      count: this.matches.length,
                      list: this.matches.map((x) => `${x.username || '#' + x.userId} (${ts(`action.${x.action}`)}, ${date(x.createdAt)})`).join('; '),
                    })
                  : t('log.check_none')}
              </p>
            ) : null}
          </form>
        ) : null}

        {this.error ? <p className="MemberDesk-error">{this.error}</p> : null}
        {this.loading ? <LoadingIndicator display="block" /> : null}

        <div className="MemberDesk-scope">
          <div className="MemberDesk-tableWrap">
            <table className="MemberDesk-table MemberDesk-table--log">
              <thead>
                <tr>
                  <th scope="col">{t('log.when')}</th>
                  <th scope="col">{t('log.who')}</th>
                  <th scope="col">{t('log.action')}</th>
                  <th scope="col">{t('log.member')}</th>
                  <th scope="col">{t('log.detail')}</th>
                </tr>
              </thead>
              <tbody>
                {this.rows.map((e) => (
                  <tr>
                    <td className="MemberDesk-muted" title={e.createdAt}>
                      {ago(e.createdAt)}
                    </td>
                    <td className="MemberDesk-name">{e.actor?.displayName || t('log.system')}</td>
                    <td>
                      <b>{t(`action.${e.action}`)}</b>
                      {e.status !== 'done' ? <span className="MemberDesk-pill MemberDesk-pill--warn">{t(`log.status_${e.status}`)}</span> : null}
                    </td>
                    <td>{this.member(e)}</td>
                    <td className="MemberDesk-muted MemberDesk-wrap">{describe(e, group)}</td>
                  </tr>
                ))}
                {!this.rows.length && !this.loading ? (
                  <tr>
                    <td colSpan={5} className="MemberDesk-empty">
                      {t('log.none')}
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>

          <ul className="MemberDesk-cards">
            {this.rows.map((e) => (
              <li className="MemberDesk-card MemberDesk-card--log">
                <div className="MemberDesk-cardBody">
                  <b className="MemberDesk-name">
                    {t(`action.${e.action}`)} · {this.member(e)}
                  </b>
                  <span className="MemberDesk-username">
                    {t('log.by', { actor: e.actor?.displayName || ts('log.system'), ago: ago(e.createdAt) })}
                  </span>
                  <span className="MemberDesk-muted">{describe(e, group)}</span>
                </div>
              </li>
            ))}
          </ul>
        </div>

        <nav className="MemberDesk-pager" aria-label={ts('list.pages')}>
          <span className="MemberDesk-muted">{t('list.page_of', { page: this.page, pages })}</span>
          <span className="MemberDesk-pagerBtns">
            <Button
              className="Button"
              disabled={this.page <= 1}
              onclick={() => {
                this.page--;
                this.load();
              }}
            >
              {t('list.prev')}
            </Button>
            <Button
              className="Button"
              disabled={this.page >= pages}
              onclick={() => {
                this.page++;
                this.load();
              }}
            >
              {t('list.next')}
            </Button>
          </span>
        </nav>
      </div>
    );
  }

  member(e: any): Mithril.Children {
    if (e.member) {
      return (
        <a className="MemberDesk-who" href={desk().member(e.member.id)} onclick={(ev: MouseEvent) => this.go(ev, desk().member(e.member.id))}>
          {avatar(e.member, 'sm')}
          <span className="MemberDesk-name">{e.member.displayName}</span>
        </a>
      );
    }
    if (e.before?.username)
      return (
        <span>
          {e.before.username} <span className="MemberDesk-faint">({t('log.gone')})</span>
        </span>
      );
    if (e.userId) return <span className="MemberDesk-faint">#{e.userId}</span>;
    return <span className="MemberDesk-faint">—</span>;
  }

  runCheck(e: SubmitEvent) {
    e.preventDefault();
    this.checking = true;
    api('POST', '/hash-check', { value: this.check.trim() })
      .then((res: any) => (this.matches = res.matches))
      .catch((err) => app.alerts.show({ type: 'error' }, errorText(err)))
      .finally(() => {
        this.checking = false;
        m.redraw();
      });
  }
}
