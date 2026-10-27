import type { InjectionKey, ShallowRef } from 'vue';

export interface ModalFooterContext {
    target: ShallowRef<HTMLElement | null>;
    claim: (claimed: boolean) => void;
}

export const modalFooterKey: InjectionKey<ModalFooterContext> =
    Symbol('modalFooter');
