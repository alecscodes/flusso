import { useColorMode } from '@vueuse/core';
import { computed } from 'vue';

export function useTheme() {
    const mode = useColorMode({
        attribute: 'class',
        modes: {
            light: 'light',
            dark: 'dark',
        },
    });

    const isDark = computed(() => mode.value === 'dark');
    const isLight = computed(() => mode.value === 'light');

    function setTheme(theme: 'light' | 'dark' | 'auto') {
        mode.value = theme;
    }

    function toggleTheme() {
        mode.value = mode.value === 'dark' ? 'light' : 'dark';
    }

    return {
        theme: mode,
        isDark,
        isLight,
        setTheme,
        toggleTheme,
    };
}
