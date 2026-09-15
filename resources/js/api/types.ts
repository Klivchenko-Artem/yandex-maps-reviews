export interface User {
    id: number;
    name: string;
    email: string;
}

export type SyncStatus = 'queued' | 'running' | 'retrying' | 'completed' | 'failed' | 'source_changed';

export interface SyncRun {
    id: number;
    status: SyncStatus;
    is_active: boolean;
    progress: number;
    pages_total: number | null;
    pages_done: number;
    attempts: number;
    reviews_fetched: number;
    reviews_created: number;
    reviews_updated: number;
    reviews_removed: number;
    error: { code: string; message: string } | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
}

export interface Organization {
    id: number;
    source: string;
    external_id: string;
    url: string;
    name: string | null;
    address: string | null;
    rating: number | null;
    ratings_count: number;
    reviews_count: number;
    last_synced_at: string | null;
    sync: SyncRun | null;
}

export interface Review {
    id: number;
    author: { name: string; avatar_url: string | null };
    rating: number;
    text: string;
    business_reply: string | null;
    published_at: string;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface Paginated<T> {
    data: T[];
    meta: PaginationMeta;
}

export interface FieldChange<T = unknown> {
    old: T;
    new: T;
}

export interface SnapshotEntry {
    captured_at: string;
    rating: number | null;
    ratings_count: number;
    reviews_count: number;
    is_first: boolean;
    changes: Partial<Record<'name' | 'address' | 'rating' | 'ratings_count' | 'reviews_count', FieldChange>>;
    reviews_created: number | null;
    reviews_updated: number | null;
    reviews_removed: number | null;
}

export interface ReviewRevisionEntry {
    event: 'changed' | 'removed' | 'restored';
    review_id: number;
    author_name: string;
    changes: Record<string, FieldChange>;
    created_at: string;
}

export interface History {
    snapshots: SnapshotEntry[];
    review_revisions: ReviewRevisionEntry[];
}
