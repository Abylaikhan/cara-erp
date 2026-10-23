<script setup lang="ts">
// «Деньги» → the window «Расчётный счёт» — legacy bankAccountModal (L6691–6743), openBankAccountModal/saveBankAccount/
// deleteBankAccount (L15121–15170). Legacy kept the change in the page only and showed the current balance field (always 0)
// as «Начальный остаток»; here the account is saved with its opening balance (answer of the accountant 23.10, № 37; ADR-0047).
// The server keeps the type and currency of an account with operations and does not delete it.
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import type { MoneyAccount } from '@/lib/money';

const m = reactive({
    open: false,
    id: null as number | null,
    title: '',
    type: 'bank' as 'bank' | 'cash',
    name: '',
    bank: '',
    iik: '',
    bik: '',
    opening: '0',
    currency: 'KZT',
    lockVersion: 0,
    busy: false,
});
function openBankAccountModal(acc: MoneyAccount | null, defaultType?: 'cash'): void {
    Object.assign(m, {
        open: true,
        id: acc?.id ?? null,
        title: acc ? acc.name : defaultType === 'cash' ? 'Новая касса' : 'Новый расчётный счёт',
        type: acc?.type || defaultType || 'bank',
        name: acc?.name || '',
        bank: acc?.bank || '',
        iik: acc?.iik || '',
        bik: acc?.bik || '',
        opening: acc ? acc.openingText : '0',
        currency: acc?.currency || 'KZT',
        lockVersion: acc?.lock_version ?? 0,
    });
}
defineExpose({ openBankAccountModal });

const options = {
    preserveScroll: true,
    onStart: () => (m.busy = true),
    onFinish: () => (m.busy = false),
    onSuccess: () => (m.open = false),
    onError: (e: Record<string, string>) => window.alert(Object.values(e)[0] || 'Ошибка'),
};
function saveBankAccount(): void {
    if (!m.name.trim()) {
        window.alert('Укажите название счёта');
        return;
    }
    const data = { type: m.type, name: m.name, bank: m.bank, iik: m.iik, bik: m.bik, opening: m.opening, currency: m.currency, lock_version: m.lockVersion };
    if (m.id === null) router.post('/money/accounts', data, options);
    else router.put(`/money/accounts/${m.id}`, data, options);
}
function deleteBankAccount(): void {
    if (m.id === null || !window.confirm('Удалить счёт? Все операции по нему сохранятся.')) return;
    router.delete(`/money/accounts/${m.id}`, { ...options, data: { lock_version: m.lockVersion } });
}
</script>

<template>
    <ErpModal id="bankAccountModal" :open="m.open" max-width="460px" @close="m.open = false">
        <div class="modal-header">
            <span id="bank-account-modal-title" class="modal-title">{{ m.title }}</span>
            <button class="modal-close" @click="m.open = false">✕</button>
        </div>
        <div style="display: grid; gap: 12px; padding: 0 4px">
            <div class="form-row">
                <label class="label">Тип</label>
                <select id="ba-type" v-model="m.type" style="font-size: 13px">
                    <option value="bank">🏦 Расчётный счёт</option>
                    <option value="cash">💵 Касса</option>
                </select>
            </div>
            <div class="form-row">
                <label class="label">Название *</label>
                <input id="ba-name" v-model="m.name" type="text" placeholder="Народный Банк — основной" />
            </div>
            <div class="form-row">
                <label class="label">Банк</label>
                <input id="ba-bank" v-model="m.bank" type="text" placeholder="АО «Народный Банк Казахстана»" />
            </div>
            <div class="form-row">
                <label class="label">ИИК (номер счёта)</label>
                <input id="ba-iin" v-model="m.iik" type="text" placeholder="KZ486017131000005619" />
            </div>
            <div class="form-row">
                <label class="label">БИК</label>
                <input id="ba-bik" v-model="m.bik" type="text" placeholder="HSBKKZKX" />
            </div>
            <div class="form-row">
                <label class="label">Начальный остаток (₸)</label>
                <input id="ba-balance" v-model="m.opening" type="number" min="0" step="0.01" />
            </div>
            <div class="form-row">
                <label class="label">Валюта</label>
                <select id="ba-currency" v-model="m.currency" style="font-size: 13px">
                    <option value="KZT">₸ Тенге (KZT)</option>
                    <option value="USD">$ Доллар (USD)</option>
                    <option value="EUR">€ Евро (EUR)</option>
                    <option value="RUB">₽ Рубль (RUB)</option>
                </select>
            </div>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
            <button
                id="ba-delete-btn"
                class="btn btn-ghost btn-sm"
                style="margin-right: auto; color: var(--red)"
                :style="{ display: m.id !== null ? '' : 'none' }"
                :disabled="m.busy"
                @click="deleteBankAccount"
            >
                🗑 Удалить
            </button>
            <button class="btn btn-ghost" @click="m.open = false">Отмена</button>
            <button class="btn btn-primary" :disabled="m.busy" @click="saveBankAccount">Сохранить</button>
        </div>
    </ErpModal>
</template>
