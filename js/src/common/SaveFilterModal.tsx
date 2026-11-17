import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { errorText, jsonApi, savedFilters, t } from './util';

export interface SaveFilterAttrs extends IFormModalAttrs {
  isAdmin: boolean;
  query: Record<string, any>;
  onSaved: (filters: any[]) => void;
}

/** Name the current filters; admins can share them with every staff member. */
export default class SaveFilterModal extends FormModal<SaveFilterAttrs> {
  name = '';
  shared = false;
  error: string | null = null;

  className() {
    return 'MemberDeskSaveFilter Modal--small';
  }

  title() {
    return t('filters.save_title');
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        {this.error ? <p className="MemberDesk-error">{this.error}</p> : null}
        <div className="Form-group">
          <label for="md-filter-name">{t('filters.name')}</label>
          <input
            id="md-filter-name"
            className="FormControl"
            maxlength={80}
            value={this.name}
            oninput={(e: InputEvent) => (this.name = (e.target as HTMLInputElement).value)}
          />
        </div>
        {this.attrs.isAdmin ? (
          <div className="Form-group">
            <label className="checkbox">
              <input type="checkbox" checked={this.shared} onchange={(e: Event) => (this.shared = (e.target as HTMLInputElement).checked)} />
              {t('filters.share')}
            </label>
          </div>
        ) : null}
        <div className="Form-group">
          <Button type="submit" className="Button Button--primary Button--block" loading={this.loading} disabled={!this.name.trim()}>
            {t('filters.save_button')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    jsonApi('POST', 'member-desk-filters', { name: this.name.trim(), shared: this.shared, query: this.attrs.query })
      .then(() => savedFilters())
      .then((filters: any[]) => {
        this.attrs.onSaved(filters);
        this.hide();
      })
      .catch((err) => {
        this.error = errorText(err);
        this.loading = false;
        m.redraw();
      });
  }
}
