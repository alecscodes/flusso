import 'vue-sonner/style.css';

import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { createInertiaApp } from '@inertiajs/vue3';

const appName = import.meta.env.VITE_APP_NAME || 'Flusso';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    pages: './pages',
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('Auth/'):
                return AuthLayout;
            case name.startsWith('Settings/'):
                return SettingsLayout;
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#7C3AED',
    },
});

initializeTheme();
initializeFlashToast();
