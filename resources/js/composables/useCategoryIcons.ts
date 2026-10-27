import { shallowRef } from 'vue';

type CategoryIcons = typeof import('@/lib/categoryIcons');

const icons = shallowRef<CategoryIcons | null>(null);
let loading: Promise<void> | null = null;

/**
 * The full lucide icon set (~1600 icons) lives in its own lazy chunk so it
 * stays out of the page bundles. Components render a placeholder until it has
 * loaded; the module is fetched once and shared.
 */
export function useCategoryIcons() {
    loading ??= import('@/lib/categoryIcons').then((module) => {
        icons.value = module;
    });

    return icons;
}
