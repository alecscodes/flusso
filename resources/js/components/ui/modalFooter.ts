import type { InjectionKey } from 'vue';

/** The id of the Modal's footer element, where <ModalFooter> teleports its buttons. */
export const modalFooterId: InjectionKey<string> = Symbol('modalFooterId');
