import { api, type GroupInfo, type Row } from './util';

export interface Filters {
  q: string;
  groups: string[];
  status: string[];
  joinedFrom: string;
  joinedTo: string;
  seenFrom: string;
  seenTo: string;
  joinedWithin: string;
  notSeenFor: string;
  minPosts: string;
  maxPosts: string;
  reportedMin: string;
  reportedWithin: string;
}

export interface SavedFilter {
  id: number;
  name: string;
  shared: boolean;
  mine: boolean;
  canEdit: boolean;
  query: Partial<Filters> & { sort?: string; dir?: string };
}

export interface Meta {
  groups: GroupInfo[];
  extensions: Record<string, boolean>;
  can: Record<string, boolean>;
  filters: SavedFilter[];
  gdprDeletionOff: boolean;
  /** Where report counts come from for this viewer: Docket, flarum/flags, or nothing. */
  reports: 'docket' | 'flags' | null;
}

export const emptyFilters = (): Filters => ({
  q: '',
  groups: [],
  status: [],
  joinedFrom: '',
  joinedTo: '',
  seenFrom: '',
  seenTo: '',
  joinedWithin: '',
  notSeenFor: '',
  minPosts: '',
  maxPosts: '',
  reportedMin: '',
  reportedWithin: '',
});

/**
 * The list's state lives as long as the page does (one per app), so opening a member and coming
 * back keeps the filters, sort, page and selection.
 */
export default class DeskState {
  filters: Filters = emptyFilters();
  sort = 'joinedAt';
  dir: 'asc' | 'desc' = 'desc';
  page = 1;
  perPage = 50;
  rows: Row[] = [];
  total = 0;
  meta: Meta | null = null;
  loading = false;
  error: string | null = null;
  selected = new Set<number>();
  allMatching = false;
  activeFilter: number | null = null;
  private seq = 0;

  params(): Record<string, any> {
    const p: Record<string, any> = { sort: this.sort, dir: this.dir, page: this.page, perPage: this.perPage };
    Object.entries(this.filters).forEach(([k, v]) => {
      if (Array.isArray(v) ? v.length : v !== '') p[k] = v;
    });
    return p;
  }

  /** Filters only (what a bulk "all matching" target and the CSV use). */
  filterParams(): Record<string, any> {
    const p = this.params();
    delete p.page;
    delete p.perPage;
    return p;
  }

  load(): Promise<void> {
    const seq = ++this.seq;
    this.loading = true;
    this.error = null;
    const params = this.params();
    if (this.meta && this.page > 1) params.rowsOnly = 1;

    return api('GET', '/members', undefined, params)
      .then((res: any) => {
        if (seq !== this.seq) return;
        this.rows = res.rows;
        this.total = res.total;
        if (res.groups) this.meta = res as Meta;
      })
      .catch((e: any) => {
        if (seq !== this.seq) return;
        this.error = e?.response?.errors?.[0]?.detail || 'error';
      })
      .finally(() => {
        if (seq === this.seq) {
          this.loading = false;
          m.redraw();
        }
      });
  }

  activeCount(): number {
    const f = this.filters;
    return (
      f.groups.length +
      f.status.length +
      [f.joinedFrom, f.joinedTo, f.seenFrom, f.seenTo, f.joinedWithin, f.notSeenFor, f.minPosts, f.maxPosts, f.reportedMin].filter((v) => v !== '')
        .length
    );
  }

  setFilter<K extends keyof Filters>(key: K, value: Filters[K]) {
    this.filters[key] = value;
    this.page = 1;
    this.allMatching = false;
    this.activeFilter = null;
    this.load();
  }

  toggleIn(key: 'groups' | 'status', value: string) {
    const list = this.filters[key];
    this.setFilter(key, list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);
  }

  clear() {
    this.filters = { ...emptyFilters(), q: this.filters.q };
    this.page = 1;
    this.allMatching = false;
    this.activeFilter = null;
    this.load();
  }

  apply(saved: SavedFilter) {
    const { sort, dir, ...rest } = saved.query;
    this.filters = { ...emptyFilters(), ...(rest as any) };
    if (sort) this.sort = sort;
    if (dir === 'asc' || dir === 'desc') this.dir = dir;
    this.page = 1;
    this.allMatching = false;
    this.selected.clear();
    this.activeFilter = saved.id;
    this.load();
  }

  sortBy(key: string) {
    if (this.sort === key) this.dir = this.dir === 'asc' ? 'desc' : 'asc';
    else {
      this.sort = key;
      this.dir = key === 'username' || key === 'email' ? 'asc' : 'desc';
    }
    this.page = 1;
    this.load();
  }

  goto(page: number) {
    this.page = page;
    this.load();
  }

  pages(): number {
    return Math.max(1, Math.ceil(this.total / this.perPage));
  }

  toggle(id: number) {
    if (this.allMatching) {
      this.allMatching = false;
      this.rows.forEach((r) => this.selected.add(r.id));
    }
    this.selected.has(id) ? this.selected.delete(id) : this.selected.add(id);
  }

  pageSelected(): boolean {
    return this.rows.length > 0 && this.rows.every((r) => this.allMatching || this.selected.has(r.id));
  }

  togglePage() {
    const all = this.pageSelected();
    this.allMatching = false;
    this.rows.forEach((r) => (all ? this.selected.delete(r.id) : this.selected.add(r.id)));
  }

  selectionCount(): number {
    return this.allMatching ? this.total : this.selected.size;
  }

  clearSelection() {
    this.allMatching = false;
    this.selected.clear();
  }

  target(): Record<string, any> {
    return this.allMatching ? { all: true, filters: this.filterParams() } : { ids: Array.from(this.selected) };
  }

  group(id: number): GroupInfo | undefined {
    return this.meta?.groups.find((g) => g.id === id);
  }
}

let shared: DeskState | null = null;
export const deskState = () => (shared ??= new DeskState());
