import app from 'flarum/admin/app';
import AdminPage from 'flarum/admin/components/AdminPage';
import type Mithril from 'mithril';
import MemberList from '../common/MemberList';
import MemberView from '../common/MemberView';
import LogView from '../common/LogView';
import { setLinks } from '../common/util';

const EXT = 'ernestdefoe-member-desk';
const a = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${EXT}.admin.${key}`, params);

function forumUrl(path: string): string {
  return `${app.forum.attribute('baseUrl')}${path}`;
}

/**
 * Member Desk takes over the admin's Users page (core's comes back when Member Desk is switched
 * off). Admin routes are hash routes, so links are `#/users/…`.
 */
class DeskAdminPage extends AdminPage {
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

class DeskMemberAdminPage extends AdminPage {
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

class DeskLogAdminPage extends AdminPage {
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

app.initializers.add(EXT, () => {
  app.routes.users = { path: '/users', component: DeskAdminPage };
  app.routes['memberDesk.log'] = { path: '/users-log', component: DeskLogAdminPage };
  app.routes['memberDesk.member'] = { path: '/users/:id', component: DeskMemberAdminPage };

  setLinks({
    list: () => '#' + app.route('users'),
    member: (id: number) => '#' + app.route('memberDesk.member', { id }),
    log: () => '#' + app.route('memberDesk.log'),
    profile: (username: string) => forumUrl(`/u/${encodeURIComponent(username)}`),
    discussion: (id: number, slug: string, near?: number) => forumUrl(`/d/${id}${slug ? '-' + slug : ''}${near ? '/' + near : ''}`),
    admin: true,
  });

  app.registry
    .for(EXT)
    .registerSetting({
      setting: `${EXT}.retention_days`,
      type: 'number',
      min: 1,
      max: 3650,
      label: a('settings.retention'),
      help: a('settings.retention_help'),
    })
    .registerSetting(() => (
      <div className="Form-group">
        <a className="Button Button--primary" href={'#' + app.route('users')}>
          {a('settings.open')}
        </a>
      </div>
    ))
    .registerPermission({ icon: 'fas fa-address-book', label: a('permissions.view'), permission: `${EXT}.view` }, 'moderate', 90)
    .registerPermission({ icon: 'fas fa-envelope', label: a('permissions.view_emails'), permission: `${EXT}.viewEmails` }, 'moderate', 89)
    .registerPermission({ icon: 'fas fa-network-wired', label: a('permissions.view_ips'), permission: `${EXT}.viewIps` }, 'moderate', 88)
    .registerPermission({ icon: 'fas fa-skull-crossbones', label: a('permissions.mark_spammer'), permission: `${EXT}.markSpammer` }, 'moderate', 87)
    .registerPermission({ icon: 'fas fa-file-csv', label: a('permissions.export'), permission: `${EXT}.export` }, 'moderate', 86);
});
