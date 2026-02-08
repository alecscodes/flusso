import { icons } from 'lucide-vue-next';
import type { Component } from 'vue';

function pascalToKebab(str: string): string {
    return str
        .replace(/([A-Z])/g, (_, c) => `-${c.toLowerCase()}`)
        .replace(/([0-9])/g, (_, c) => `-${c}`)
        .replace(/^-/, '');
}

const iconEntries = Object.entries(icons as Record<string, Component>).map(
    ([pascal, component]) => ({
        name: pascalToKebab(pascal),
        component,
    }),
);

export const categoryIconsList = iconEntries.sort((a, b) =>
    a.name.localeCompare(b.name),
);

const iconMap = Object.fromEntries(
    iconEntries.map(({ name, component }) => [name, component]),
);

const defaultIcon =
    (iconMap['zap'] as Component) ?? (Object.values(iconMap)[0] as Component);

export function getCategoryIconComponent(
    name: string | null | undefined,
): Component | null {
    if (!name) return null;
    return (iconMap[name] as Component) ?? defaultIcon;
}
