<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextField from '@/Components/TextField.vue';
import { useFormFocus } from '@/composables/useFormFocus';
import { LOGIN_URL, REGISTER_URL } from '@/routes';

// canRegister is true only while no account exists (the first account becomes the administrator). `next` is the
// page the visitor was heading for, already sanitised by the server (and checked again after the post).
const props = defineProps({
    canRegister: { type: Boolean, default: false },
    next: { type: String, default: null },
});

const form = useForm({ email: '', password: '', next: props.next ?? '' });
const { formRef, alertRef } = useFormFocus(form);

function submit() {
    if (form.processing) {
        return;
    }

    form.post(LOGIN_URL, {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <section aria-labelledby="login-heading" class="mx-auto w-full max-w-sm space-y-6">
        <h1 id="login-heading" class="text-2xl font-semibold tracking-tight">Sign in</h1>

        <p v-if="form.errors.form" ref="alertRef" role="alert" tabindex="-1" class="rounded-md border border-danger px-3 py-2 text-sm text-danger">
            {{ form.errors.form }}
        </p>

        <form ref="formRef" class="space-y-4" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.email"
                label="Email"
                name="email"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
                required
                autofocus
            />
            <TextField
                v-model="form.password"
                label="Password"
                name="password"
                type="password"
                autocomplete="current-password"
                :error="form.errors.password"
                required
            />

            <PrimaryButton :disabled="form.processing">Sign in</PrimaryButton>
        </form>

        <p v-if="canRegister" class="text-sm text-muted">
            No account exists yet.
            <Link :href="REGISTER_URL" class="font-medium text-accent hover:text-accent-hover">Create the first account</Link>
            (it becomes the administrator).
        </p>
    </section>
</template>
