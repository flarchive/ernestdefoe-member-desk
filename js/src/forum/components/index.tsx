import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import type Mithril from 'mithril';
import MemberList from '../../common/MemberList';
import MemberView from '../../common/MemberView';
import LogView from '../../common/LogView';
import css from './deskCss.generated';

/*
 * The page's styles arrive with this chunk (see build-css.cjs). A constructed stylesheet first:
 * it isn't an inline <style>, so a strict Content-Security-Policy doesn't block it.
 */
(function addStyles() {
  if (document.getElementById('member-desk-css') || (document as any).memberDeskCss) return;
  try {
    const sheet = new CSSStyleSheet();
    sheet.replaceSync(css);
    document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet];
    (document as any).memberDeskCss = true;
  } catch {
    const style = document.createElement('style');
    style.id = 'member-desk-css';
    style.textContent = css;
    document.head.appendChild(style);
  }
})();

/*
 * The forum's Member Desk for staff who can't open the admin (moderators by default). One lazy
 * chunk: nobody else ever downloads it.
 */

abstract class DeskPage extends Page {
  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'MemberDeskForumPage';
    app.history.push('member-desk', app.translator.trans('ernestdefoe-member-desk.forum.title') as any);
    app.setTitle(app.translator.trans('ernestdefoe-member-desk.forum.title') as any);
  }

  view() {
    const can = app.forum.attribute<any>('memberDesk');
    return (
      <div className="MemberDeskForum">
        <div className="container">
          <h1 className="MemberDeskForum-title">{app.translator.trans('ernestdefoe-member-desk.forum.title')}</h1>
          {can?.view ? this.body() : <p className="MemberDesk-error">{app.translator.trans('ernestdefoe-member-desk.forum.no_access')}</p>}
        </div>
      </div>
    );
  }

  abstract body(): Mithril.Children;
}

export class ForumListPage extends DeskPage {
  body() {
    return <MemberList />;
  }
}

export class ForumMemberPage extends DeskPage {
  body() {
    return <MemberView id={Number(m.route.param('id'))} />;
  }
}

export class ForumLogPage extends DeskPage {
  body() {
    return <LogView />;
  }
}
