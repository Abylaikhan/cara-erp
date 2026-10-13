<script setup lang="ts">
// Legacy shell: sidebar (L2006–2143) and topbar (L2147–2158).
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);
const current = computed(() => page.props.page?.id ?? null);
const title = computed(() => page.props.page?.title ?? page.props.appName);
const canMrp = computed(() => page.props.nav.some((s) => s.items.some((i) => i.id === 'mrp')));
const isLight = ref(typeof document !== 'undefined' && document.body.classList.contains('light-theme'));

// Legacy toast (saveProduct L1121): green box bottom-right for 3 seconds.
const toast = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => page.props.flash.toast,
    (message) => {
        if (!message) return;
        toast.value = message;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => (toast.value = null), 3000);
    },
    { immediate: true },
);

function toggleTheme(): void {
    const light = document.body.classList.toggle('light-theme');
    localStorage.setItem('erp_theme', light ? 'light' : 'dark');
    isLight.value = light;
}

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <nav id="sidebar">
        <div class="logo">
            <div class="logo-mark">PT</div>
            <div class="logo-sub">PromTech ERP</div>
        </div>

        <div v-for="section in page.props.nav" :key="section.label" class="nav-section">
            <div class="nav-label">{{ section.label }}</div>
            <Link v-for="item in section.items" :key="item.id" :href="item.href" class="nav-item" :class="{ active: item.id === current }">
                <!-- eslint-disable-next-line vue/no-v-html -- static SVG from config/erp.php, copied from legacy -->
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" v-html="item.icon" />
                <span>{{ item.label }}</span>
            </Link>
        </div>

        <div class="sidebar-bottom">
            <div class="user-card" style="flex-direction: column; gap: 8px; align-items: stretch">
                <div style="display: flex; align-items: center; gap: 10px">
                    <div id="sidebar-avatar" class="avatar" :style="user ? { background: user.color + '33', color: user.color } : undefined">
                        {{ user?.initials ?? 'АД' }}
                    </div>
                    <div class="user-info">
                        <div id="sidebar-username" class="user-name">{{ user?.name ?? '—' }}</div>
                        <div id="sidebar-role" class="user-role">{{ user?.roleLabel ?? '—' }}</div>
                    </div>
                </div>
                <button
                    class="sidebar-logout"
                    style="
                        width: 100%;
                        padding: 7px;
                        background: transparent;
                        border: 1px solid var(--border);
                        border-radius: 6px;
                        color: var(--text3);
                        font-size: 12px;
                        cursor: pointer;
                        transition: all 0.15s;
                        text-align: center;
                    "
                    @click="logout"
                >
                    ↩ Выйти из системы
                </button>
            </div>
        </div>
    </nav>

    <div id="main">
        <div id="main-topbar" class="topbar">
            <div id="topbar-title" class="page-title">{{ title }}</div>
            <div id="topbar-sub" class="breadcrumb"></div>
            <div class="topbar-right">
                <button id="theme-toggle-btn" class="btn btn-ghost btn-sm" title="Переключить тему" style="font-size: 16px; padding: 4px 8px" @click="toggleTheme">
                    {{ isLight ? '🌙' : '☀️' }}
                </button>
                <component :is="canMrp ? Link : 'div'" class="notif-btn" v-bind="canMrp ? { href: '/mrp' } : {}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                    <div class="notif-dot"></div>
                </component>
                <button class="btn btn-ghost btn-sm" title="Обновить данные из базы" style="font-size: 16px; padding: 4px 8px" @click="router.reload()">🔄</button>
            </div>
        </div>

        <div id="content">
            <slot />
        </div>
    </div>
    <div
        v-if="toast"
        style="position: fixed; bottom: 20px; right: 20px; background: var(--green); color: #fff; padding: 10px 16px; border-radius: 8px; font-size: 13px; z-index: 9999"
    >
        {{ toast }}
    </div>
</template>
