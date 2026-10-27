import type { User } from '@/types';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: { user: User | null };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
