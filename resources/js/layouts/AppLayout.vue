<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import { computed } from 'vue';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <div class="layout">
        <header class="layout__header">
            <Link href="/" class="layout__brand">{{ page.props.appName }}</Link>
            <div v-if="user" class="layout__user">
                <span>{{ user.name }}</span>
                <Link href="/logout" method="post" as="span">
                    <Button label="Выйти" icon="pi pi-sign-out" size="small" severity="secondary" text />
                </Link>
            </div>
        </header>
        <main class="layout__main">
            <slot />
        </main>
    </div>
</template>

<style scoped>
.layout__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1.5rem;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
}

.layout__brand {
    font-weight: 600;
    font-size: 1.1rem;
    color: inherit;
    text-decoration: none;
}

.layout__user {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--app-muted);
}

.layout__main {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem;
}
</style>
