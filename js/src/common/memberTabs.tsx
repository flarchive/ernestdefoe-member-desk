import app from 'flarum/common/app';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { ago, avatar, date, dayjs, desk, groupChip, isForever, num, t, trustPill, ts, type GroupInfo } from './util';
import type MemberView from './MemberView';

/*
 * The member page's tabs, each drawn from the MemberView it belongs to.
 */

export function profileTab(v: MemberView): Mithril.Children {
  const d = v.data;
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

export function groupsTab(v: MemberView): Mithril.Children {
  const d = v.data;
  const r = d.member;
  const canEdit = d.can.addGroup;
  const addedAt = (id: number) => d.log.find((e: any) => e.action === 'addGroup' && e.after?.group === id);

  return (
    <section className="MemberDesk-card">
      <h3>{t('groups.title')}</h3>
      <ul className="MemberDesk-switches">
        {v.groups().map((g) => {
          const on = r.groups.includes(g.id);
          const locked = !canEdit || (g.id === 1 && !d.can.editAdmin) || r.anonymized;
          const added = on ? addedAt(g.id) : null;
          return (
            <li>
              <span className="MemberDesk-switchLabel">
                {groupChip(g)}
                {added ? (
                  <span className="MemberDesk-muted">{t('groups.added', { date: date(added.createdAt), by: added.actor?.displayName || '—' })}</span>
                ) : null}
              </span>
              <button
                type="button"
                role="switch"
                aria-checked={on ? 'true' : 'false'}
                aria-label={ts('groups.toggle', { name: g.name })}
                className={`MemberDesk-switch${on ? ' is-on' : ''}`}
                disabled={locked || v.busy === `g${g.id}`}
                onclick={() => v.toggleGroup(g.id, on)}
              />
            </li>
          );
        })}
      </ul>
      <p className="helpText">{t('groups.help')}</p>
    </section>
  );
}

export function activityTab(v: MemberView): Mithril.Children {
  const a = v.data.activity;
  const months = a.months as { month: string; posts: number }[];
  const peak = Math.max(1, ...months.map((x) => x.posts));
  const sessions = v.data.sessions;

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
                  <Icon name={/iPhone|Android/.test(s.agent) ? 'fas fa-mobile-screen' : 'fas fa-desktop'} /> {s.agent || t('activity.unknown_device')}
                  {s.ip ? <span className="MemberDesk-mono MemberDesk-muted"> · {s.ip}</span> : null}
                </span>
                <span className="MemberDesk-muted">{t('activity.last_used', { ago: ago(s.lastActivityAt || s.createdAt) })}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="MemberDesk-faint">{t('activity.no_sessions')}</p>
        )}
        {sessions.length && v.data.can.signOut ? (
          <Button className="Button" icon="fas fa-right-from-bracket" onclick={() => v.confirmAction('signOut')}>
            {t('member.sign_out')}
          </Button>
        ) : null}
      </section>
    </div>
  );
}

export function reportsTab(v: MemberView): Mithril.Children {
  const r = v.data.reports;
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
      <span className={`MemberDesk-pill MemberDesk-pill--${{ open: 'warn', in_progress: 'info', resolved: 'ok', rejected: 'muted' }[st] || 'muted'}`}>
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

export function ipsTab(v: MemberView): Mithril.Children {
  const ips = v.data.ips || [];
  const canBan = v.data.can.banIp;
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
                <Button className="Button Button--small" icon="fas fa-gavel" loading={v.busy === `ban${ip.ip}`} onclick={() => v.banIp(ip.ip)}>
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
                  <a className="MemberDesk-other" href={desk().member(o.id)} onclick={(e: MouseEvent) => v.go(e, desk().member(o.id))}>
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

export function notesTab(v: MemberView): Mithril.Children {
  const n = v.data.notes;
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
        <form className="MemberDesk-noteForm" onsubmit={(e: SubmitEvent) => v.addNote(e)}>
          <label for="md-note" className="visually-hidden">
            {t('notes.add_label')}
          </label>
          <textarea
            id="md-note"
            className="FormControl"
            rows={3}
            placeholder={ts('notes.placeholder')}
            value={v.noteText}
            oninput={(e: InputEvent) => (v.noteText = (e.target as HTMLTextAreaElement).value)}
          />
          <Button type="submit" className="Button Button--primary" loading={v.noteSaving} disabled={!v.noteText.trim()}>
            {t('notes.add')}
          </Button>
        </form>
      ) : null}
    </section>
  );
}

export function warningsTab(v: MemberView): Mithril.Children {
  const d = v.data;
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
        {history.length ? timeline(v, history) : <p className="MemberDesk-faint">{t('warnings.no_history')}</p>}
      </section>
    </div>
  );
}

export function logTab(v: MemberView): Mithril.Children {
  const log = v.data.log;
  return (
    <section className="MemberDesk-card">
      <h3>{t('log.member_title')}</h3>
      {log.length ? timeline(v, log) : <p className="MemberDesk-faint">{t('log.none')}</p>}
    </section>
  );
}

export function timeline(v: MemberView, entries: any[]): Mithril.Children {
  return (
    <ol className="MemberDesk-timeline">
      {entries.map((e) => (
        <li className={`MemberDesk-event MemberDesk-event--${e.action}`}>
          <span className="MemberDesk-eventDot" aria-hidden="true" />
          <div>
            <b>{t(`action.${e.action}`)}</b> {describe(e, (id) => v.group(id))}
            {e.status !== 'done' ? <span className="MemberDesk-pill MemberDesk-pill--warn">{t(`log.status_${e.status}`)}</span> : null}
            <div className="MemberDesk-muted">{t('log.by', { actor: e.actor?.displayName || ts('log.system'), ago: ago(e.createdAt) })}</div>
          </div>
        </li>
      ))}
    </ol>
  );
}

// --- actions

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
