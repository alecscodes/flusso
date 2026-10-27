import { shallowRef, watch, type Ref } from 'vue';

/**
 * Follows `source` but keeps the last non-null value, so a dialog's content
 * stays rendered while the dialog fades out.
 */
export function useLastValue<T>(source: Ref<T | null>): Ref<T | null> {
    const last = shallowRef<T | null>(source.value);

    watch(source, (value) => {
        if (value !== null) {
            last.value = value;
        }
    });

    return last;
}
