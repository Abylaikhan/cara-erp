<script setup lang="ts">
// M12 «Деньги», part 1 — legacy page-money (L6002–6320): period, KPI (renderMoneyKPIs), tabs «Касса», «Расчётные счета» and
// «Авансовые отчёты», «Все операции» (CashTab, BankTab, AdvancesTab, AllTab). View only: new operations, editing, transfers and statement import come with
// ERP-031…035 (decisions D-03, D-11); the salon worksheet, bank reconciliation and the payment calendar are next.
import { computed, ref } from 'vue';
import AdvancesTab from '@/components/money/AdvancesTab.vue';
import AllTab from '@/components/money/AllTab.vue';
import BankTab from '@/components/money/BankTab.vue';
import CashTab from '@/components/money/CashTab.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { accountStats, fmt, hasPeriod, LATER, period, setMoneyPeriod, type Categories, type MoneyAccount, type MoneyTx } from '@/lib/money';

const props = defineProps<{
    accounts: MoneyAccount[];
    transactions: MoneyTx[];
    advanceDebt: number;
    categories: Categories;
    advances?: InstanceType<typeof AdvancesTab>['$props']['advances'];
}>();

type Tab = 'cash' | 'bank' | 'salonwb' | 'bankrecon' | 'advances' | 'all' | 'calendar';
const tab = ref<Tab>('cash');
const tabs: { id: Tab; label: string }[] = [
    { id: 'cash', label: '💵 Касса' },
    { id: 'bank', label: '🏦 Расчётные счета' },
    { id: 'salonwb', label: '🧾 Рабочий лист салонов' },
    { id: 'bankrecon', label: '🔍 Сверка с банком' },
    { id: 'advances', label: '📋 Авансовые отчёты' },
    { id: 'all', label: '📄 Все операции' },
    { id: 'calendar', label: '📅 Платёжный календарь' },
];
const LATER_TABS: Partial<Record<Tab, string>> = {
    salonwb: 'Рабочий лист салонов переносится следующей частью раздела «Деньги» (ERP-051).',
    bankrecon: 'Сверка с банком появится вместе с импортом банковских выписок (ERP-034).',
    calendar: 'Платёжный календарь переносится следующей частью раздела «Деньги».',
};
const visited = ref(new Set<Tab>(['cash']));
function show(t: Tab): void {
    tab.value = t;
    visited.value.add(t);
}

// The period of the page (legacy setMoneyPeriod); custom dates are taken as they are typed.
setMoneyPeriod('all');
const periodBtn = (mode: string) => (period.mode === mode ? { background: 'var(--accent)', color: '#fff' } : undefined);
const periodLabel = computed(() => {
    if (!period.from) return '';
    const ru = (d: string) => d.split('-').reverse().join('.');
    return ru(period.from) + ' — ' + (period.to ? ru(period.to) : '');
});
function custom(field: 'from' | 'to', value: string): void {
    period[field] = value;
    period.mode = 'custom';
}

// ── KPI (legacy renderMoneyKPIs): turnover of the period, or the current balance without one ──
const kpi = computed(() => {
    const sum = (type: string) => props.accounts.filter((a) => a.type === type).reduce((s, a) => s + accountStats(props.transactions, a.id).balance, 0);
    const cash = sum('cash');
    const bank = sum('bank');
    return { cash, bank, total: cash + bank, cashCount: props.accounts.filter((a) => a.type === 'cash').length };
});
const hint = computed(() => (hasPeriod() ? ' (оборот)' : ''));
const fab = ref(true);
const add = () => window.alert(LATER.add);
</script>

<template>
    <ErpLayout>
        <div id="page-money" class="page active">
            <!-- Плавающие кнопки для быстрого добавления -->
            <div
                id="money-fab"
                style="position: fixed; bottom: 24px; right: 24px; z-index: 200; flex-direction: column; gap: 8px; align-items: flex-end"
                :style="{ display: tab === 'cash' || tab === 'bank' ? 'flex' : 'none' }"
            >
                <button class="btn btn-ghost btn-sm" style="padding: 4px 8px; border-radius: 20px; opacity: 0.5; font-size: 11px; align-self: flex-end" @click="fab = !fab">≡</button>
                <div id="money-fab-btns" style="flex-direction: column; gap: 8px; align-items: flex-end" :style="{ display: fab ? 'flex' : 'none' }">
                    <button class="btn btn-primary btn-sm" style="padding: 8px 16px; border-radius: 20px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); white-space: nowrap" @click="add">
                        📥 + Приход
                    </button>
                    <button
                        class="btn btn-ghost btn-sm"
                        style="padding: 8px 16px; border-radius: 20px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); white-space: nowrap; background: var(--bg2)"
                        @click="add"
                    >
                        📤 + Расход
                    </button>
                </div>
            </div>
            <div class="section-header">
                <div>
                    <div class="section-title">Деньги</div>
                    <div class="section-sub">Касса · Расчётные счета · Авансовые отчёты</div>
                </div>
                <button class="btn btn-primary btn-sm" @click="add">+ Операция</button>
            </div>

            <!-- Фильтр периода — общий для всех вкладок -->
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 14px; padding: 8px 14px; background: var(--bg3); border-radius: 8px; flex-wrap: wrap">
                <span style="font-size: 13px; color: var(--text2); font-weight: 600">📅 Период:</span>
                <button id="mp-btn-month" class="btn btn-ghost btn-sm" :style="periodBtn('month')" @click="setMoneyPeriod('month')">Месяц</button>
                <button id="mp-btn-quarter" class="btn btn-ghost btn-sm" :style="periodBtn('quarter')" @click="setMoneyPeriod('quarter')">Квартал</button>
                <button id="mp-btn-year" class="btn btn-ghost btn-sm" :style="periodBtn('year')" @click="setMoneyPeriod('year')">Год</button>
                <button id="mp-btn-all" class="btn btn-ghost btn-sm" :style="periodBtn('all')" @click="setMoneyPeriod('all')">Всё время</button>
                <input id="mp-date-from" :value="period.from" type="date" style="font-size: 13px" @change="custom('from', ($event.target as HTMLInputElement).value)" />
                <span style="color: var(--text3)">—</span>
                <input id="mp-date-to" :value="period.to" type="date" style="font-size: 13px" @change="custom('to', ($event.target as HTMLInputElement).value)" />
                <span id="mp-period-label" style="font-size: 12px; color: var(--text3); margin-left: 4px">{{ periodLabel }}</span>
            </div>

            <!-- KPI -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px">
                <div class="card" style="padding: 14px 16px; cursor: pointer" @click="show('cash')">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">💵 Остаток в кассе</div>
                    <div id="money-kpi-cash" class="kpi-value" style="font-size: 22px; color: var(--green)">{{ fmt(kpi.cash) }} ({{ kpi.cashCount }} касс){{ hint }}</div>
                </div>
                <div class="card" style="padding: 14px 16px; cursor: pointer" @click="show('bank')">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">🏦 Остаток на счетах</div>
                    <div id="money-kpi-bank" class="kpi-value" style="font-size: 22px; color: var(--accent)">{{ fmt(kpi.bank) }}{{ hint }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">📊 Всего денег</div>
                    <div id="money-kpi-total" class="kpi-value" style="font-size: 22px">{{ fmt(kpi.total) }}{{ hint }}</div>
                </div>
                <div class="card" style="padding: 14px 16px; cursor: pointer" @click="show('advances')">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">📋 Долг по авансам</div>
                    <div id="money-kpi-advances" class="kpi-value" style="font-size: 22px; color: var(--amber)">{{ fmt(props.advanceDebt) }}</div>
                </div>
            </div>

            <!-- Вкладки -->
            <div class="tabs" style="margin-bottom: 16px">
                <div v-for="t in tabs" :key="t.id" class="tab" :class="{ active: tab === t.id }" @click="show(t.id)">{{ t.label }}</div>
            </div>

            <div v-if="visited.has('cash')" id="money-tab-cash" :style="{ display: tab === 'cash' ? '' : 'none' }">
                <CashTab :accounts="props.accounts" :txs="props.transactions" :categories="props.categories" />
            </div>
            <div v-if="visited.has('bank')" id="money-tab-bank" :style="{ display: tab === 'bank' ? '' : 'none' }">
                <BankTab :accounts="props.accounts" :txs="props.transactions" :categories="props.categories" />
            </div>
            <div v-if="visited.has('advances')" id="money-tab-advances" :style="{ display: tab === 'advances' ? '' : 'none' }">
                <AdvancesTab :advances="props.advances" :active="tab === 'advances'" />
            </div>
            <div v-if="visited.has('all')" id="money-tab-all" :style="{ display: tab === 'all' ? '' : 'none' }">
                <AllTab :accounts="props.accounts" :txs="props.transactions" :categories="props.categories" />
            </div>
            <div v-for="(text, key) in LATER_TABS" :id="'money-tab-' + key" :key="key" :style="{ display: tab === key ? '' : 'none' }">
                <div class="card">
                    <div style="font-size: 13px; color: var(--text3); line-height: 1.6">{{ text }}</div>
                </div>
            </div>
        </div>
    </ErpLayout>
</template>
