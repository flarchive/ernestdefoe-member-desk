import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import UserControls from 'flarum/forum/utils/UserControls';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import { setLinks } from '../common/links';

const EXT = 'ernestdefoe-member-desk';

export { default as extend } from './extend';

const canView = () => !!app.forum.attribute<any>('memberDesk')?.view;

app.initializers.add(EXT, () => {
  const base = () => app.forum.attribute<string>('baseUrl');
  setLinks({
    list: () => app.route('member-desk.index'),
    member: (id: number) => app.route('member-desk.member', { id }),
    log: () => app.route('member-desk.log'),
    profile: (username: string) => app.route('user', { username }),
    discussion: (id: number, slug: string, near?: number) => `${base()}/d/${id}${slug ? '-' + slug : ''}${near ? '/' + near : ''}`,
    admin: false,
  });

  extend(SessionDropdown.prototype, 'items', function (items: ItemList<Mithril.Children>) {
    if (!canView()) return;
    items.add(
      'memberDesk',
      <LinkButton icon="fas fa-address-book" href={app.route('member-desk.index')}>
        {app.translator.trans(`${EXT}.forum.nav`)}
      </LinkButton>,
      55
    );
  });

  extend(UserControls, 'moderationControls', function (items: ItemList<Mithril.Children>, user: any) {
    if (!canView()) return;
    items.add(
      'memberDesk',
      <LinkButton icon="fas fa-address-card" href={app.route('member-desk.member', { id: user.id() })}>
        {app.translator.trans(`${EXT}.forum.open_member`)}
      </LinkButton>,
      -50
    );
  });
});
