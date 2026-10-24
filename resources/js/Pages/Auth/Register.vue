<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextField from '@/Components/TextField.vue';
import { LOGIN_URL, REGISTER_URL } from '@/routes';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

function submit() {
    form.post(REGISTER_URL, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Create the first account" />

    <section aria-labelledby="register-heading" class="mx-auto w-full max-w-sm space-y-6">
        <div class="space-y-2">
            <h1 id="register-heading" class="text-2xl font-semibold tracking-tight">Create the first account</h1>
            <p class="text-sm text-muted">This account becomes the administrator. Registration closes afterwards.</p>
        </div>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.name"
                label="Name"
                name="name"
                autocomplete="name"
                :error="form.errors.name"
                required
                autofocus
            />
            <TextField
                v-model="form.email"
                label="Email"
                name="email"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
                required
            />
            <TextField
                v-model="form.password"
                label="Password"
                name="password"
                type="password"
                autocomplete="new-password"
                hint="At least 12 characters."
                :error="form.errors.password"
                required
            />
            <TextField
                v-model="form.password_confirmation"
                label="Confirm password"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                :error="form.errors.password_confirmation"
                required
            />

            <PrimaryButton :disabled="form.processing">Create account</PrimaryButton>
        </form>

        <p class="text-sm text-muted">
            Already have an account?
            <Link :href="LOGIN_URL" class="font-medium text-accent hover:text-accent-hover">Sign in</Link>
        </p>
    </section>
</template>
