<script setup lang="ts">
// Tab «Все операции» of «Деньги» — legacy money-tab-all (L6293–6317), renderAllTx (L10292): every operation of the period,
// newest first, with the cash/bank, category and text filters.
import { computed, reactive } from 'vue';
import { catLabel, fmt, inPeriod, LATER, type Categories, type MoneyAccount, type MoneyTx } from '@/lib/money';

const props = defineProps<{ accounts: MoneyAccount[]; txs: MoneyTx[]; categories: Categories }>();
const f = reactive({ search: '', type: 'all', cat: 'all' });
const accounts = computed(() => new Map(props.accounts.map((a) => [a.id, a])));
const allCats = computed(() => [...Object.entries(props.categories.income), ...Object.entries(props.categories.expense)]);
const rows = computed(() => {
    const q = f.search.toLowerCase();
    return props.txs
        .filter((t) => inPeriod(t))
        .filter((t) => f.type === 'all' || accounts.value.get(t.account)?.type === f.type)
        .filter((t) => f.cat === 'all' || t.category === f.cat)
        .filter((t) => !q || (t.desc + t.ctr + t.order).toLowerCase().includes(q));
});
const later = () => window.alert(LATER.edit);
</script>

<template>
    <div style="display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap">
        <input id="money-search" v-model="f.search" type="text" placeholder="Поиск..." style="flex: 1; max-width: 280px; font-size: 13px" />
        <select id="money-type-filter" v-model="f.type" style="font-size: 13px; width: auto">
            <option value="all">Все</option>
            <option value="cash">Касса</option>
            <option value="bank">Банк</option>
        </select>
        <select id="money-cat-filter" v-model="f.cat" style="font-size: 13px; width: auto">
            <option value="all">Все категории</option>
            <option v-for="[id, c] in allCats" :key="id" :value="id">{{ c[0] }} {{ c[1] }}</option>
        </select>
    </div>
    <div class="card" style="padding: 0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Счёт</th>
                    <th>Категория</th>
                    <th>Статья</th>
                    <th>Описание</th>
                    <th>Контрагент</th>
                    <th style="text-align: right; color: var(--green)">Приход</th>
                    <th style="text-align: right; color: var(--red)">Расход</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="all-tx-tbody">
                <tr v-for="t in rows" :key="t.id" v-memo="[t]">
                    <td style="font-size: 12px">{{ t.date }}</td>
                    <td style="font-size: 12px">{{ accounts.get(t.account)?.name || '—' }}</td>
                    <td style="font-size: 12px">{{ catLabel(props.categories, t.category) }}</td>
                    <td style="font-size: 12px; color: var(--text3)">{{ t.item || '—' }}</td>
                    <td style="font-size: 12px">
                        {{ t.desc }}<template v-for="n in t.orders" :key="n">{{ ' ' }}<span style="color: var(--accent); font-size: 11px">({{ n }})</span></template
                        ><template v-if="t.esf > 0">{{ ' ' }}<span class="badge badge-green" style="font-size: 10px" :title="`Привязано к ЭСФ на ${fmt(t.esf)}`">🔗 ЭСФ</span></template>
                    </td>
                    <td style="font-size: 12px; color: var(--text3)">{{ t.ctr || '—' }}</td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; color: var(--green)">{{ t.flow === 'income' ? fmt(t.amount) : '—' }}</td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; color: var(--red)">{{ t.flow === 'expense' ? fmt(t.amount) : '—' }}</td>
                    <td style="white-space: nowrap">
                        <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="later">✏️</button>{{ ' ' }}<button class="btn btn-ghost btn-sm" style="font-size: 11px" title="Копировать" @click="later">📋</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
