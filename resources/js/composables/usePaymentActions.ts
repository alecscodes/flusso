import { destroy, markUnpaid, skip, unskip } from '@/routes/payments';
import type { Payment } from '@/types';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

// Shared on purpose: every payment card talks to the one <PaymentDialogs> on the page.
const paying = ref<Payment | null>(null);
const editing = ref<Payment | null>(null);

const options = { preserveScroll: true };

function paymentName(payment: Payment): string {
    return payment.description || payment.recurring_payment?.name || 'Payment';
}

/** Whether the payment's rule runs only once (so deleting it deletes the rule). */
export function runsOnce(payment: Payment): boolean {
    return payment.recurring_payment?.installments === 1;
}

export function usePaymentActions() {
    return {
        paying,
        editing,
        pay: (payment: Payment) => (paying.value = payment),
        edit: (payment: Payment) => (editing.value = payment),
        skip: (payment: Payment) =>
            router.patch(skip.url(payment), {}, options),
        unskip: (payment: Payment) =>
            router.patch(unskip.url(payment), {}, options),
        markUnpaid: (payment: Payment) =>
            router.patch(markUnpaid.url(payment), {}, options),
        remove: (payment: Payment) => {
            if (
                confirm(
                    `Delete "${paymentName(payment)}"? It is only planned once, so nothing else is affected.`,
                )
            ) {
                router.delete(destroy.url(payment), options);
            }
        },
    };
}

export { paymentName };
