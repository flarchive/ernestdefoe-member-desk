import Extend from 'flarum/common/extenders';

/*
 * forum.js only wires routes and two links for staff; the pages are one lazy chunk.
 */
const lazy = (name: 'ForumListPage' | 'ForumMemberPage' | 'ForumLogPage') => () =>
  import('./components').then((chunk) => ({ default: (chunk as any)[name] }));

export default [
  // /member-desk/log before /member-desk/:id: routes match in order.
  new Extend.Routes()
    .add('member-desk.index', '/member-desk', lazy('ForumListPage'))
    .add('member-desk.log', '/member-desk/log', lazy('ForumLogPage'))
    .add('member-desk.member', '/member-desk/:id', lazy('ForumMemberPage')),
];
