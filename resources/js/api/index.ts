import { ensureCsrfCookie, http } from './http';
import type { History, Organization, Paginated, Review, SyncRun, User } from './types';

export const authApi = {
    async login(email: string, password: string, remember: boolean): Promise<User> {
        await ensureCsrfCookie();
        const { data } = await http.post<{ data: User }>('/login', { email, password, remember });
        return data.data;
    },
    async logout(): Promise<void> {
        await http.post('/logout');
    },
    async me(): Promise<User> {
        const { data } = await http.get<{ data: User }>('/user');
        return data.data;
    },
};

export const organizationsApi = {
    async list(): Promise<Organization[]> {
        const { data } = await http.get<{ data: Organization[] }>('/organizations');
        return data.data;
    },
    async create(url: string): Promise<Organization> {
        const { data } = await http.post<{ data: Organization }>('/organizations', { url });
        return data.data;
    },
    async get(id: number): Promise<Organization> {
        const { data } = await http.get<{ data: Organization }>(`/organizations/${id}`);
        return data.data;
    },
    async remove(id: number): Promise<void> {
        await http.delete(`/organizations/${id}`);
    },
    async sync(id: number): Promise<SyncRun> {
        const { data } = await http.post<{ data: SyncRun }>(`/organizations/${id}/sync`);
        return data.data;
    },
    async reviews(id: number, page: number, signal?: AbortSignal): Promise<Paginated<Review>> {
        const { data } = await http.get<Paginated<Review>>(`/organizations/${id}/reviews`, { params: { page }, signal });
        return data;
    },
    async history(id: number): Promise<History> {
        const { data } = await http.get<{ data: History }>(`/organizations/${id}/history`);
        return data.data;
    },
};
