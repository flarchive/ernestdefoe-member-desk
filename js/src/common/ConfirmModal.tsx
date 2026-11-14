import app from 'flarum/common/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { api, errorText, num, t, ts, type GroupInfo } from './util';

export interface ConfirmAttrs extends IFormModalAttrs {
  action: string;
  target: Record<string, any>;
  groups: GroupInfo[];
  gdprDeletionOff: boolean;
  onDone: (result: any) => void;
}

const ERASE = ['anonymize', 'delete'];
const DANGER = ['anonymize', 'delete', 'spammer'];

/**
 * The confirmation for every action. It names how many members, what is kept and what goes,
 * lists them (and who is skipped and why), and for erasing 25+ members, or any delete, asks the
 * staff member to type the count. The count confirmed is sent along (`expect`), so if the list
 * changed in between, nothing runs.
 */
export default class ConfirmModal extends FormModal<ConfirmAttrs> {
  preview: any = null;
  action!: string;
  group = '';
  until = '7';
  date = '';
  reason = '';
  message = '';
  typed = '';
  error: string | null = null;

  oninit(vnode: Mithril.Vnode<ConfirmAttrs, this>) {
    super.oninit(vnode);
    this.action = this.attrs.action;
    // Delete while gdpr's deletion is off: offer Anonymize instead (the only erasure it allows).
    if (this.action === 'delete' && this.attrs.gdprDeletionOff) this.action = 'anonymize';
    const assignable = this.attrs.groups.filter((g) => g.id !== 1);
    this.group = String(assignable[0]?.id ?? '');
    this.load();
  }

  load() {
    this.preview = null;
    this.error = null;
    api('POST', '/preview', { action: this.action === 'exportSel' ? 'export' : this.action, target: this.attrs.target, params: this.params() })
      .then((p) => (this.preview = p))
      .catch((e) => (this.error = errorText(e)))
      .finally(() => m.redraw());
  }

  params(): Record<string, any> {
    if (this.action === 'addGroup' || this.action === 'removeGroup') return { group: Number(this.group) };
    if (this.action === 'suspend') {
      return { until: this.until === 'date' ? this.date : this.until, reason: this.reason, message: this.message };
    }
    return {};
  }

  className() {
    return 'MemberDeskConfirm Modal--medium';
  }

  title() {
    const count = this.preview ? this.preview.count : null;
    return t(`confirm.title.${this.action}`, { count: count ?? '…' });
  }

  needsTyping(): boolean {
    if (!this.preview) return false;
    return ERASE.includes(this.action) && (this.action === 'delete' || this.preview.count >= 25);
  }

  content() {
    const p = this.preview;
    return (
      <div className="Modal-body MemberDeskConfirm-body">
        {this.error ? <p className="MemberDesk-error">{this.error}</p> : null}
        {ERASE.includes(this.action) ? this.modeSwitch() : null}
        {this.fields()}
        {!p && !this.error ? <LoadingIndicator display="block" /> : null}
        {p ? this.summary(p) : null}
        {p && this.needsTyping() ? (
          <div className="Form-group MemberDeskConfirm-type">
            <label for="md-type">{t('confirm.type', { count: p.count })}</label>
            <input
              id="md-type"
              className="FormControl"
              inputmode="numeric"
              autocomplete="off"
              value={this.typed}
              oninput={(e: InputEvent) => (this.typed = (e.target as HTMLInputElement).value)}
            />
          </div>
        ) : null}
        <div className="Form-group MemberDeskConfirm-actions">
          <Button className="Button" onclick={() => this.hide()}>
            {t('confirm.cancel')}
          </Button>
          <Button
            type="submit"
            className={`Button ${DANGER.includes(this.action) ? 'MemberDeskConfirm-danger' : 'Button--primary'}`}
            loading={this.loading}
            disabled={!p || p.count === 0 || (this.needsTyping() && this.typed.trim() !== String(p.count))}
          >
            {t(`confirm.go.${this.action}`, { count: p ? num(p.count) : '' })}
          </Button>
        </div>
      </div>
    );
  }

  modeSwitch() {
    const p = this.preview;
    const gdpr = p ? p.gdpr : true;
    const anonOk = p ? p.modes.anonymize : true;
    const delOk = p ? p.modes.delete : !this.attrs.gdprDeletionOff;
    return (
      <div className="MemberDeskConfirm-modes">
        <div className="MemberDesk-seg" role="radiogroup">
          <button
            type="button"
            role="radio"
            aria-checked={this.action === 'anonymize'}
            className={this.action === 'anonymize' ? 'is-on' : ''}
            disabled={!anonOk}
            onclick={() => this.switchMode('anonymize')}
          >
            {t('confirm.mode.anonymize')}
          </button>
          <button
            type="button"
            role="radio"
            aria-checked={this.action === 'delete'}
            className={this.action === 'delete' ? 'is-on' : ''}
            disabled={!delOk}
            onclick={() => this.switchMode('delete')}
          >
            {t('confirm.mode.delete')}
          </button>
        </div>
        {!gdpr ? <p className="helpText">{t('confirm.no_gdpr')}</p> : !delOk ? <p className="helpText">{t('confirm.deletion_off')}</p> : null}
      </div>
    );
  }

  switchMode(mode: string) {
    if (this.action === mode) return;
    this.action = mode;
    this.typed = '';
    this.load();
  }

  fields(): Mithril.Children {
    if (this.action === 'addGroup' || this.action === 'removeGroup') {
      const assignable = this.attrs.groups.filter((g) => g.id !== 1);
      return (
        <div className="Form-group">
          <label for="md-group">{t('confirm.group')}</label>
          <select
            id="md-group"
            className="FormControl"
            value={this.group}
            onchange={(e: Event) => {
              this.group = (e.target as HTMLSelectElement).value;
              this.load();
            }}
          >
            {assignable.map((g) => (
              <option value={String(g.id)}>{g.name}</option>
            ))}
          </select>
          <p className="helpText">{t('confirm.admin_group_note')}</p>
        </div>
      );
    }
    if (this.action === 'suspend') {
      return [
        <div className="Form-group">
          <label for="md-until">{t('confirm.until')}</label>
          <select
            id="md-until"
            className="FormControl"
            value={this.until}
            onchange={(e: Event) => (this.until = (e.target as HTMLSelectElement).value)}
          >
            <option value="1">{t('confirm.until_days', { count: 1 })}</option>
            <option value="7">{t('confirm.until_days', { count: 7 })}</option>
            <option value="30">{t('confirm.until_days', { count: 30 })}</option>
            <option value="forever">{t('confirm.until_forever')}</option>
            <option value="date">{t('confirm.until_date')}</option>
          </select>
          {this.until === 'date' ? (
            <input
              type="date"
              className="FormControl MemberDeskConfirm-date"
              aria-label={ts('confirm.until_date')}
              value={this.date}
              oninput={(e: InputEvent) => (this.date = (e.target as HTMLInputElement).value)}
            />
          ) : null}
        </div>,
        <div className="Form-group">
          <label for="md-reason">{t('confirm.reason')}</label>
          <input
            id="md-reason"
            className="FormControl"
            value={this.reason}
            oninput={(e: InputEvent) => (this.reason = (e.target as HTMLInputElement).value)}
          />
        </div>,
        <div className="Form-group">
          <label for="md-message">{t('confirm.message')}</label>
          <textarea
            id="md-message"
            className="FormControl"
            rows={2}
            value={this.message}
            oninput={(e: InputEvent) => (this.message = (e.target as HTMLTextAreaElement).value)}
          />
        </div>,
      ];
    }
    return null;
  }

  summary(p: any): Mithril.Children {
    const a = this.action;
    const counts = { count: num(p.count), posts: num(p.posts), discussions: num(p.discussions) };
    const blocks: Mithril.Children[] = [];

    if (a === 'anonymize') {
      blocks.push(<div className="MemberDesk-callout MemberDesk-callout--ok">{t('confirm.kept.anonymize', counts)}</div>);
      blocks.push(<div className="MemberDesk-callout MemberDesk-callout--danger">{t('confirm.gone.anonymize', counts)}</div>);
    } else if (a === 'delete') {
      blocks.push(
        <div className="MemberDesk-callout MemberDesk-callout--danger">
          {p.gdpr ? t('confirm.gone.delete_gdpr', counts) : t('confirm.gone.delete_core', counts)}
        </div>
      );
      blocks.push(<div className="MemberDesk-callout MemberDesk-callout--ok">{t('confirm.kept.delete', counts)}</div>);
    } else if (a === 'spammer') {
      blocks.push(
        <div className="MemberDesk-callout MemberDesk-callout--danger">
          {t(p.banIps ? 'confirm.gone.spammer_ban' : 'confirm.gone.spammer', counts)}
        </div>
      );
      blocks.push(<div className="MemberDesk-callout MemberDesk-callout--ok">{t('confirm.kept.spammer')}</div>);
    } else if (a === 'suspend') {
      blocks.push(<div className="MemberDesk-callout MemberDesk-callout--ok">{t('confirm.kept.suspend')}</div>);
    } else if (a === 'confirm') {
      blocks.push(<div className="MemberDesk-callout MemberDesk-callout--info">{t('confirm.info.confirm', { count: num(p.unconfirmed) })}</div>);
    } else if (a === 'signOut') {
      blocks.push(<div className="MemberDesk-callout MemberDesk-callout--info">{t('confirm.info.signOut')}</div>);
    } else if (a === 'unspam') {
      blocks.push(<div className="MemberDesk-callout MemberDesk-callout--info">{t('confirm.info.unspam')}</div>);
    }

    if (p.queued) blocks.push(<p className="helpText">{t('confirm.queued')}</p>);

    if (p.count > 0) {
      blocks.push(
        <div className="MemberDeskConfirm-names" tabindex="0" aria-label={ts('confirm.names_label')}>
          {p.names.map((n: any) => (
            <span>
              {n.displayName} <span className="MemberDesk-muted">@{n.username}</span>
            </span>
          ))}
          {p.count > p.names.length ? (
            <span className="MemberDesk-muted">{t('confirm.and_more', { count: num(p.count - p.names.length) })}</span>
          ) : null}
        </div>
      );
    }

    if (p.skippedCount > 0) {
      blocks.push(
        <div className="MemberDesk-callout MemberDesk-callout--info">
          <Icon name="fas fa-circle-info" /> {t('confirm.skipped', { count: num(p.skippedCount) })}{' '}
          {p.skipped.map((s: any, i: number) => [i ? ', ' : '', <b>{s.displayName}</b>, ' (', t(`skip.${s.reason}`), ')'])}
        </div>
      );
    }

    return blocks;
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const p = this.preview;
    if (!p || this.loading) return;
    this.loading = true;

    api('POST', '/run', {
      action: this.action,
      target: this.attrs.target,
      params: this.params(),
      expect: p.count + p.skippedCount,
    })
      .then((res) => {
        this.hide();
        this.attrs.onDone({ ...res, action: this.action });
      })
      .catch((err) => {
        this.loading = false;
        this.error = errorText(err);
        m.redraw();
      });
  }
}

/** Report a finished (or queued) run, and follow a queued batch until it's done. */
export function reportRun(res: any, after: () => void) {
  if (res.queued) {
    const id = app.alerts.show({ type: 'success' }, t('result.queued', { count: num(res.total) }));
    const poll = () =>
      api('GET', `/batches/${res.batch}`)
        .then((s: any) => {
          if (s.finished) {
            app.alerts.dismiss(id);
            app.alerts.show({ type: s.failedCount ? 'error' : 'success' }, t('result.done', { count: num(s.done), failed: num(s.failedCount || 0) }));
            after();
          } else {
            setTimeout(poll, 2000);
          }
        })
        .catch(() => setTimeout(poll, 4000));
    setTimeout(poll, 1500);
    return;
  }

  const failed = (res.failed || []).length;
  app.alerts.show(
    { type: failed ? 'error' : 'success' },
    failed
      ? t('result.done_failed', {
          count: num(res.done),
          failed: num(failed),
          names: res.failed
            .slice(0, 5)
            .map((f: any) => `${f.displayName} (${ts(`skip.${f.reason}`)})`)
            .join(', '),
        })
      : t('result.done_ok', { count: num(res.done) })
  );
  after();
}
