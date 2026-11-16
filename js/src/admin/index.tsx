import app from 'flarum/admin/app';
import { setLinks } from '../common/util';

export { default as extend } from './extend';

const EXT = 'ernestdefoe-member-desk';

function forumUrl(path: string): string {
  return `${app.forum.attribute('baseUrl')}${path}`;
}

app.initializers.add(EXT, () => {
  // Admin routes are hash routes, so links are `#/users/…`.
  setLinks({
    list: () => '#' + app.route('users'),
    member: (id: number) => '#' + app.route('memberDesk.member', { id }),
    log: () => '#' + app.route('memberDesk.log'),
    profile: (username: string) => forumUrl(`/u/${encodeURIComponent(username)}`),
    discussion: (id: number, slug: string, near?: number) => forumUrl(`/d/${id}${slug ? '-' + slug : ''}${near ? '/' + near : ''}`),
    admin: true,
  });
});
