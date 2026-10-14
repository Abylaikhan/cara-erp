<script setup lang="ts">
// «Лиды» form — legacy leadModal (L4375–4437), openLeadModal (L31685), saveLead (L32044). Same fields and texts; saved by the server.
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import { RELOAD, type LeadRow } from '@/lib/crm';

const modal = reactive({ open: false, id: null as number | null, lockVersion: 0, name: '', phone: '', status: 'new', interest: '', budget: '' as string | number, source: 'other', comment: '', busy: false });

function openLeadModal(lead: LeadRow | null): void {
    Object.assign(modal, {
        id: lead?.id ?? null,
        lockVersion: lead?.lock_version ?? 0,
        name: lead?.name ?? '',
        phone: lead?.phone ?? '',
        status: lead?.status ?? 'new',
        interest: lead?.interest ?? '',
        budget: lead?.budget || '',
        source: lead?.source || 'other',
        comment: lead?.comment ?? '',
        open: true,
    });
}

function saveLead(): void {
    if (!modal.name.trim()) return void window.alert('Введите имя');
    const data = { form: 'leads', name: modal.name, phone: modal.phone, status: modal.status, interest: modal.interest, budget: modal.budget, source: modal.source, comment: modal.comment };
    const options = {
        preserveScroll: true,
        preserveState: true,
        only: RELOAD,
        onStart: () => (modal.busy = true),
        onFinish: () => (modal.busy = false),
        onError: (errors: Record<string, string>) => window.alert(Object.values(errors)[0] ?? 'Ошибка сохранения'),
        onSuccess: () => (modal.open = false),
    };
    if (modal.id === null) router.post('/crm/leads', data, options);
    else router.put(`/crm/leads/${modal.id}`, { ...data, lock_version: modal.lockVersion }, options);
}

defineExpose({ openLeadModal });
</script>

<template>
    <ErpModal id="leadModal" :open="modal.open" max-width="500px" @close="modal.open = false">
        <div class="modal-header">
            <span id="lead-modal-title" class="modal-title">{{ modal.id === null ? 'Новый лид' : 'Редактировать лид' }}</span>
            <button class="modal-close" @click="modal.open = false">✕</button>
        </div>
        <div style="display: grid; gap: 12px">
            <div class="form-row">
                <label class="label">Имя / Компания *</label>
                <input id="lead-name" v-model="modal.name" type="text" placeholder="Иванов Иван" />
            </div>
            <div class="form-row cols-2">
                <div>
                    <label class="label">Телефон</label>
                    <input id="lead-phone" v-model="modal.phone" type="text" placeholder="+7 700 000 0000" />
                </div>
                <div>
                    <label class="label">Статус</label>
                    <select id="lead-status" v-model="modal.status">
                        <option value="new">🆕 Новый</option>
                        <option value="contact">📞 Контакт установлен</option>
                        <option value="meeting">🤝 Встреча назначена</option>
                        <option value="qualify">🔍 Квалификация (→ воронка)</option>
                        <option value="proposal">📄 КП отправлено (→ воронка)</option>
                        <option value="negotiation">🤝 Переговоры (→ воронка)</option>
                        <option value="won">✅ Выиграли</option>
                        <option value="lost">❌ Проиграли</option>
                    </select>
                </div>
            </div>
            <div class="form-row cols-2">
                <div>
                    <label class="label">Интересует</label>
                    <input id="lead-interest" v-model="modal.interest" type="text" placeholder="Стулья, диван..." />
                </div>
                <div>
                    <label class="label">Бюджет (₸)</label>
                    <input id="lead-budget" v-model="modal.budget" type="number" placeholder="0" />
                </div>
            </div>
            <div class="form-row">
                <label class="label">Источник</label>
                <select id="lead-source" v-model="modal.source">
                    <option value="instagram">Instagram</option>
                    <option value="referral">Рекомендация</option>
                    <option value="walk-in">Зашёл сам</option>
                    <option value="call">Звонок</option>
                    <option value="site">Сайт</option>
                    <option value="other">Другое</option>
                </select>
            </div>
            <div class="form-row">
                <label class="label">Комментарий</label>
                <input id="lead-comment" v-model="modal.comment" type="text" placeholder="Заметки..." />
            </div>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
            <button class="btn btn-ghost" @click="modal.open = false">Отмена</button>
            <button class="btn btn-primary" :disabled="modal.busy" @click="saveLead">💾 Сохранить</button>
        </div>
    </ErpModal>
</template>
