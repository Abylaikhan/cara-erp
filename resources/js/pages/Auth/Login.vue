<script setup lang="ts">
// Legacy login screen (L1871–1937, styles L471–499). The quick-login block with credentials is not carried over:
// passwords must never reach the browser (ТЗ AUTH-01, docs/adr/0002-ui-deviations.md).
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const tab = ref<'pass' | 'pin'>('pass');
const form = useForm({ login: '', password: '' });
const box = ref<HTMLElement | null>(null);
const passwordInput = ref<HTMLInputElement | null>(null);
const pin = ref('');
const pinError = ref('');
const pinBusy = ref(false);

const activeTab = 'background:var(--accent);color:#fff;border-color:var(--accent)';
const idleTab = 'background:transparent;color:var(--text2);border-color:var(--border)';
const tabBase = 'flex:1;padding:8px;border-radius:8px;border:2px solid;font-size:13px;font-weight:600;cursor:pointer;';
const pinDisplay = computed(() => [...pin.value.split('').map(() => '●'), ...Array(4 - pin.value.length).fill('_')].join(' '));

function shake(): void {
    const el = box.value;
    if (!el) return;
    el.style.animation = 'none';
    void el.offsetHeight;
    el.style.animation = 'loginShake .4s ease';
}

function doLogin(): void {
    form.clearErrors();
    if (!form.login.trim() || !form.password) {
        form.setError('login', 'Введите логин и пароль');
        return;
    }
    form.post('/login', {
        onError: () => {
            form.reset('password');
            shake();
        },
    });
}

function focusPassword(): void {
    nextTick(() => passwordInput.value?.focus());
}

function switchLoginTab(next: 'pass' | 'pin'): void {
    tab.value = next;
    if (next === 'pin') {
        pin.value = '';
        pinError.value = '';
    }
}

function pinPadPress(key: string): void {
    if (pinBusy.value) return;
    if (key === '⌫') {
        pin.value = pin.value.slice(0, -1);
    } else if (/[0-9]/.test(key) && pin.value.length < 4) {
        pin.value += key;
    }
    if (pin.value.length === 4) checkLoginPin();
}

function checkLoginPin(): void {
    pinBusy.value = true;
    router.post(
        '/login/pin',
        { pin: pin.value },
        {
            preserveState: true,
            onError: (errors) => {
                pinError.value = errors.pin ?? 'Неверный PIN-код';
                setTimeout(() => {
                    pin.value = '';
                    pinError.value = '';
                    pinBusy.value = false;
                }, 1000);
            },
            onSuccess: () => {
                pinBusy.value = false;
            },
        },
    );
}

const pinKeyStyle =
    'padding:14px;font-size:18px;font-weight:600;border-radius:10px;border:1px solid var(--border);background:var(--bg3);color:var(--text1);cursor:pointer';
</script>

<template>
    <div id="login-screen">
        <div ref="box" class="login-box">
            <div class="login-logo">🪑 PromTech ERP</div>
            <div class="login-sub">Мебельное производство · Алматы</div>

            <div style="display: flex; gap: 8px; margin-bottom: 20px">
                <button id="tab-login-pass" :style="tabBase + (tab === 'pass' ? activeTab : idleTab)" @click="switchLoginTab('pass')">🔑 Логин / Пароль</button>
                <button id="tab-login-pin" :style="tabBase + (tab === 'pin' ? activeTab : idleTab)" @click="switchLoginTab('pin')">🔢 PIN-код</button>
            </div>

            <div v-show="tab === 'pass'" id="login-pass-form">
                <div class="login-field">
                    <label>Логин</label>
                    <input id="login-username" v-model="form.login" type="text" placeholder="Введите логин" autocomplete="username" @keydown.enter.prevent="focusPassword" />
                </div>
                <div class="login-field">
                    <label>Пароль</label>
                    <input
                        id="login-password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        placeholder="Введите пароль"
                        autocomplete="current-password"
                        @keydown.enter.prevent="doLogin"
                    />
                </div>
                <div id="login-error" class="login-error">{{ form.errors.login }}</div>
                <button id="login-btn" class="login-btn" :disabled="form.processing" @click="doLogin">Войти в систему</button>
            </div>

            <div v-show="tab === 'pin'" id="login-pin-form">
                <div style="text-align: center; margin-bottom: 16px">
                    <div style="font-size: 13px; color: var(--text3); margin-bottom: 12px">Введите ваш PIN-код (4 цифры)</div>
                    <div
                        id="login-pin-display"
                        style="font-size: 36px; font-family: monospace; letter-spacing: 12px; font-weight: 700; color: var(--text1); margin-bottom: 16px; min-height: 48px"
                    >
                        {{ pinDisplay }}
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; max-width: 240px; margin: 0 auto">
                        <button v-for="d in ['1', '2', '3', '4', '5', '6', '7', '8', '9']" :key="d" :style="pinKeyStyle" @click="pinPadPress(d)">{{ d }}</button>
                        <div></div>
                        <button :style="pinKeyStyle" @click="pinPadPress('0')">0</button>
                        <button :style="pinKeyStyle.replace('color:var(--text1)', 'color:var(--red)')" @click="pinPadPress('⌫')">⌫</button>
                    </div>
                </div>
                <div id="login-pin-error" class="login-error">{{ pinError }}</div>
            </div>
        </div>
    </div>
</template>
