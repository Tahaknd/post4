import { nextTick, ref, watch } from 'vue';

/**
 * Moves keyboard focus to what went wrong after a form submission that came back with errors, so a
 * keyboard or screen reader user lands on the problem instead of on a page that silently changed.
 *
 * Bind `formRef` to the <form> and, if the form can show an error that belongs to no field, `alertRef` to
 * that banner (it needs tabindex="-1" to be focusable). After a submission that ends with errors the first
 * field marked aria-invalid="true" gets focus (TextField sets it from its `error` prop); when no field is
 * marked, the banner does.
 *
 * It waits for the response, not for a change of the errors object, so the same error twice in a row still
 * moves focus back. Nothing is focused when the submission succeeded or while it is running.
 *
 * @param {{ processing: boolean, hasErrors: boolean }} form An Inertia useForm() instance.
 */
export function useFormFocus(form) {
    const formRef = ref(null);
    const alertRef = ref(null);

    watch(
        () => form.processing,
        async (processing, wasProcessing) => {
            if (processing || !wasProcessing || !form.hasErrors) {
                return;
            }

            // The error messages and aria-invalid are rendered on the next tick.
            await nextTick();

            const invalid = formRef.value?.querySelector('[aria-invalid="true"]');

            (invalid ?? alertRef.value)?.focus();
        },
    );

    return { formRef, alertRef };
}
