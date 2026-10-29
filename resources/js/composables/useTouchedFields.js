import { reactive } from 'vue';

/**
 * Which fields of a form the user has been through. A field's message appears only after the user has left it, never
 * while it is still being filled in. Mark a control with `data-field="<name>"` and put `onFocusOut` on the form: leaving
 * the control adds its field (a field may be several controls that share the name, like a filter's key and value; a
 * control without a `data-field` is ignored).
 */
export function useTouchedFields() {
    const touched = reactive(new Set());

    function onFocusOut(event) {
        const field = event.target.closest?.('[data-field]')?.dataset.field;

        if (field !== undefined) {
            touched.add(field);
        }
    }

    return { touched, onFocusOut };
}
