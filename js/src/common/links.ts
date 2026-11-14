/**
 * Where a member's page and the list live (admin and forum differ). Its own module so forum.js,
 * which loads for every visitor, carries only this and not the page helpers.
 */
export interface DeskLinks {
  list: () => string;
  member: (id: number, tab?: string) => string;
  log: () => string;
  profile: (username: string) => string;
  discussion: (id: number, slug: string, near?: number) => string;
  admin: boolean;
}

let links: DeskLinks;
export const setLinks = (l: DeskLinks) => (links = l);
export const desk = () => links;
