import type { User } from '@/types';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: { user: User | null; canManageInstance: boolean };
            sidebarOpen: boolean;
            /** The HTML `passwordrules` attribute for password inputs. */
            passwordRules: string;
        };
    }
}
