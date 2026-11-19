import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import { DeskAdminPage, DeskLogAdminPage, DeskMemberAdminPage } from './pages';

const EXT = 'ernestdefoe-member-desk';
const a = (key: string) => app.translator.trans(`${EXT}.admin.${key}`);

export default [
  // Member Desk takes over the admin's Users page (core's comes back when it's switched off).
  new Extend.Routes()
    .add('users', '/users', DeskAdminPage)
    .add('memberDesk.log', '/users-log', DeskLogAdminPage)
    .add('memberDesk.member', '/users/:id', DeskMemberAdminPage),

  new Extend.Admin()
    .setting(
      () => ({
        setting: `${EXT}.retention_days`,
        type: 'number',
        min: 1,
        max: 3650,
        label: a('settings.retention'),
        help: a('settings.retention_help'),
      }),
      100
    )
    .customSetting(
      () => (
        <div className="Form-group">
          <a className="Button Button--primary" href={'#' + app.route('users')}>
            {a('settings.open')}
          </a>
        </div>
      ),
      90
    )
    .permission(() => ({ icon: 'fas fa-address-book', label: a('permissions.view'), permission: `${EXT}.view` }), 'moderate', 90)
    .permission(() => ({ icon: 'fas fa-envelope', label: a('permissions.view_emails'), permission: `${EXT}.viewEmails` }), 'moderate', 89)
    .permission(() => ({ icon: 'fas fa-network-wired', label: a('permissions.view_ips'), permission: `${EXT}.viewIps` }), 'moderate', 88)
    .permission(() => ({ icon: 'fas fa-skull-crossbones', label: a('permissions.mark_spammer'), permission: `${EXT}.markSpammer` }), 'moderate', 87)
    .permission(() => ({ icon: 'fas fa-file-csv', label: a('permissions.export'), permission: `${EXT}.export` }), 'moderate', 86),
];
