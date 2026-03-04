import 'vue-sonner/style.css';
import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { Toaster, toast } from 'vue-sonner';

const appName = import.meta.env.VITE_APP_NAME || 'Flusso';

router.on('success', (event) => {
    const flash = event.detail.page?.props?.flash as
        | { success?: string; error?: string }
        | undefined;
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({
            render: () => [
                h(App, props),
                h(Toaster, { theme: 'system', position: 'top-right' }),
            ],
        })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#7C3AED',
    },
});
