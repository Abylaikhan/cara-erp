<script setup lang="ts">
// Orders table of «Продажи» — legacy thead of orders-all-orders/-queue/-progress/-done (L3955–4127) and the row of
// renderOrders (L30131–30163). The sort arrows show the current column in every table (legacy updated only the first table
// in the page, «Очередь», because the four tables share the element ids).
import { fmtCurrency, markOrderDone, ORDER_FORM_LATER, paidOf, PROGRESS_CLASS, STATUS_BADGE, STATUS_LABELS, type OrderRow } from '@/lib/sales';

const props = defineProps<{ orders: OrderRow[]; channels: Record<string, string>; sortCol: string; sortAsc: boolean }>();
const emit = defineEmits<{ sort: [col: string] }>();

const icon = (col: string) => (col === props.sortCol ? (props.sortAsc ? ' ▲' : ' ▼') : '');
// Legacy resolveCustomerName: a salon's or dealer's order shows the channel, an office order its customer.
const customer = (o: OrderRow) => (o.channel !== 'office' && props.channels[o.channel]) || o.customer || '—';
const openOrder = () => window.alert(ORDER_FORM_LATER);
</script>

<template>
    <table class="data-table">
        <thead>
            <tr>
                <th style="cursor: pointer; user-select: none" @click="emit('sort', 'num')">Номер <span>{{ icon('num') }}</span></th>
                <th style="cursor: pointer; user-select: none" @click="emit('sort', 'customer')">Покупатель <span>{{ icon('customer') }}</span></th>
                <th>Конечный клиент</th>
                <th style="cursor: pointer; user-select: none; text-align: right" @click="emit('sort', 'total')">Сумма <span>{{ icon('total') }}</span></th>
                <th style="cursor: pointer; user-select: none; text-align: right" @click="emit('sort', 'paid')">Оплачено <span>{{ icon('paid') }}</span></th>
                <th style="cursor: pointer; user-select: none; text-align: right" @click="emit('sort', 'debt')">К оплате <span>{{ icon('debt') }}</span></th>
                <th style="cursor: pointer; user-select: none" @click="emit('sort', 'date')">Дата <span>{{ icon('date') }}</span></th>
                <th>Прогресс</th>
                <th>Срок</th>
                <th>Статус</th>
                <th>Продавец</th>
                <th>% оплаты</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="o in props.orders" :key="o.id" v-memo="[o, props.channels]" style="cursor: pointer" title="Открыть заказ для редактирования" @click="openOrder">
                <td style="font-family: 'IBM Plex Mono', monospace; color: var(--accent)">{{ o.num }}</td>
                <td>{{ customer(o) }}</td>
                <td style="font-size: 12px; color: var(--text3)">{{ o.end_client || '—' }}</td>
                <td style="text-align: right; font-family: 'IBM Plex Mono', monospace">{{ fmtCurrency(o.total) }}</td>
                <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; color: var(--green)">{{ paidOf(o) > 0 ? fmtCurrency(paidOf(o)) : '—' }}</td>
                <td style="text-align: right; font-family: 'IBM Plex Mono', monospace" :style="{ color: o.total - paidOf(o) > 0 ? 'var(--red)' : 'var(--text3)' }">
                    {{ o.total - paidOf(o) > 0 ? fmtCurrency(o.total - paidOf(o)) : '✅' }}
                </td>
                <td style="font-size: 12px; color: var(--text3)">{{ o.created || '—' }}</td>
                <td>
                    <div style="display: flex; align-items: center; gap: 8px">
                        <div class="progress" style="flex: 1"><div class="progress-fill" :class="PROGRESS_CLASS[o.status]" :style="{ width: o.progress + '%' }"></div></div>
                        <span style="font-size: 11px; font-family: 'IBM Plex Mono', monospace; color: var(--text3)">{{ o.progress }}%</span>
                    </div>
                </td>
                <td style="font-size: 12px">{{ o.date }}</td>
                <td>
                    <span class="badge" :class="STATUS_BADGE[o.status]">{{ STATUS_LABELS[o.status] }}</span
                    ><button
                        v-if="o.can_complete"
                        class="btn btn-ghost btn-sm"
                        style="font-size: 10px; color: var(--green); padding: 1px 6px; margin-left: 4px"
                        @click.stop="markOrderDone(o)"
                    >
                        &#10003; Завершить
                    </button>
                </td>
                <td style="font-size: 12px">{{ o.seller || '—' }}</td>
                <td
                    style="font-family: 'IBM Plex Mono', monospace; font-size: 12px"
                    :style="{ color: o.payment >= 100 ? 'var(--green)' : o.payment > 0 ? 'var(--amber)' : 'var(--red)' }"
                >
                    {{ o.payment }}%
                </td>
            </tr>
        </tbody>
    </table>
</template>
