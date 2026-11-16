import app from 'flarum/admin/app';
import AdminPage from 'flarum/admin/components/AdminPage';
import type Mithril from 'mithril';
import MemberList from '../common/MemberList';
import MemberView from '../common/MemberView';
import LogView from '../common/LogView';

const EXT = 'ernestdefoe-member-desk';
const a = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${EXT}.admin.${key}`, params);

/**
 * Member Desk takes over the admin's Users page (core's comes back when Member Desk is switched
 * off). Admin routes are hash routes, so links are `#/users/…`.
 */
export class DeskAdminPage extends AdminPage {
  headerInfo() {
    return {
      className: 'MemberDeskAdminPage',
      icon: 'fas fa-users',
      title: a('page.title'),
      description: a('page.description'),
    };
  }

  content(): Mithril.Children {
    return <MemberList />;
  }
}

export class DeskMemberAdminPage extends AdminPage {
  headerInfo() {
    return {
      className: 'MemberDeskAdminPage MemberDeskAdminPage--member',
      icon: 'fas fa-address-card',
      title: a('page.member_title'),
      description: a('page.member_description'),
    };
  }

  content(): Mithril.Children {
    return <MemberView id={Number(m.route.param('id'))} />;
  }
}

export class DeskLogAdminPage extends AdminPage {
  headerInfo() {
    return {
      className: 'MemberDeskAdminPage MemberDeskAdminPage--log',
      icon: 'fas fa-clock-rotate-left',
      title: a('page.log_title'),
      description: a('page.log_description'),
    };
  }

  content(): Mithril.Children {
    return <LogView />;
  }
}
