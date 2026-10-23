<script setup lang="ts">
// «Склад» → a document — legacy whDocViewModal (L6533–6541), viewWhDoc (L28638–28680). Same header, lines and total; besides
// legacy, the supplier and the ESF of a receipt, and for a draft «✏️ Изменить» and «✅ Провести» (legacy could not open a
// draft again — ADR-0002, item 123; ADR-0049).
import { router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import type { StockDoc } from '@/components/warehouse/StockDocModal.vue';
import type { SharedProps } from '@/types';

const emit = defineEmits<{ edit: [doc: StockDoc] }>();
const page = usePage<SharedProps>();
const v = reactive({ open: false, doc: null as StockDoc | null, busy: false });
function viewWhDoc(doc: StockDoc): void {
    Object.assign(v, { open: true, doc });
}
defineExpose({ viewWhDoc });
const TYPE: Record<string, string> = { receipt: 'Приходная накладная', transfer: 'Перемещение', writeoff: 'Требование-накладная' };
const total = (d: StockDoc) => d.items.reduce((s, it) => s + it.qty * it.price, 0);
function edit(): void {
    if (!v.doc) return;
    v.open = false;
    emit('edit', v.doc);
}
function post(): void {
    const d = v.doc;
    if (!d) return;
    router.post(
        `/warehouse/documents/${d.id}/post`,
        { lock_version: d.lock_version },
        {
            preserveScroll: true,
            onStart: () => (v.busy = true),
            onFinish: () => (v.busy = false),
            onSuccess: () => {
                v.open = false;
                if (page.props.flash.alert) window.alert(page.props.flash.alert);
            },
            onError: (e: Record<string, string>) => window.alert(Object.values(e)[0] || 'Ошибка'),
        },
    );
}
</script>

<template>
    <ErpModal id="whDocViewModal" :open="v.open" max-width="680px" @close="v.open = false">
        <template v-if="v.doc">
            <div class="modal-header">
                <span id="wh-docview-title" class="modal-title">{{ TYPE[v.doc.type] || v.doc.type }} № {{ v.doc.num }}</span>
                <button class="modal-close" @click="v.open = false">✕</button>
            </div>
            <div id="wh-docview-body" style="font-size: 13px">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; font-size: 13px">
                    <div><span style="color: var(--text3)">Дата:</span> <b>{{ v.doc.date }}</b></div>
                    <div><span style="color: var(--text3)">Номер:</span> <b>{{ v.doc.num }}</b></div>
                    <div v-if="v.doc.supplier"><span style="color: var(--text3)">Поставщик:</span> {{ v.doc.supplier }}</div>
                    <div v-if="v.doc.esf_ref"><span style="color: var(--text3)">ЭСФ:</span> {{ v.doc.esf_ref }}</div>
                    <div><span style="color: var(--text3)">Автор:</span> {{ v.doc.createdBy || '—' }}</div>
                    <div v-if="v.doc.note" style="grid-column: 1/-1"><span style="color: var(--text3)">Примечание:</span> {{ v.doc.note }}</div>
                </div>
                <table class="data-table" style="margin-bottom: 12px">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Наименование</th>
                            <th style="text-align: right">Кол-во</th>
                            <th>Ед.</th>
                            <th style="text-align: right">Цена</th>
                            <th style="text-align: right">Сумма</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(it, i) in v.doc.items" :key="i">
                            <td style="text-align: center; color: var(--text3)">{{ i + 1 }}</td>
                            <td style="font-weight: 500">{{ it.name }}</td>
                            <td style="text-align: right; font-family: 'IBM Plex Mono', monospace">{{ it.qty }}</td>
                            <td style="color: var(--text3)">{{ it.unit }}</td>
                            <td style="text-align: right; font-family: 'IBM Plex Mono', monospace">₸{{ it.price.toLocaleString('ru-RU') }}</td>
                            <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-weight: 600">₸{{ it.amount.toLocaleString('ru-RU') }}</td>
                        </tr>
                    </tbody>
                </table>
                <div style="text-align: right; font-size: 14px; font-weight: 600">
                    ИТОГО: <span style="color: var(--accent)">₸{{ total(v.doc).toLocaleString('ru-RU') }}</span>
                </div>
                <div v-if="v.doc.status === 'draft'" style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px">
                    <button class="btn btn-ghost btn-sm" @click="edit">✏️ Изменить</button>
                    <button class="btn btn-primary" :disabled="v.busy" @click="post">✅ Провести</button>
                </div>
            </div>
        </template>
    </ErpModal>
</template>
