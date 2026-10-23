<script setup lang="ts">
// M18 «Сдельная оплата», part 1 — legacy page-wages (L8022–8120), renderPieceWages/showPieceDetail/renderPieceTariffs
// (L17852–18050): the production staff ranked by what they earned in the chosen month from the stages posted on the
// production worksheet (the amounts are counted by the server — WagesController), with the plan, the progress and the KPIs; the
// detail of an employee; the grid of rates (legacy tariffData). View only: the plan, «+ Внести выработку» and the rates
// answer with a message until the rules of piece wages (ERP-055, decision D-06) — ADR-0042.
import { computed, nextTick, reactive, ref } from 'vue';
import { PIECE_WORKSHOPS } from '@/components/mrp/work';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { initials, TARIFFS } from '@/lib/hr';
import { PL_MONTH_LABELS } from '@/lib/sales';

interface Staff {
    name: string;
    pos: string;
    ws: string;
    goal: number;
}
interface Entry {
    date: string;
    workshop: string;
    exec: string;
    num: string;
    pos: number;
    name: string;
    category: string;
    stage: string;
    amount: number;
}
const props = defineProps<{ today: string; staff: Staff[]; entries: Entry[]; categories: string[] }>();

const later = (text: string) => () => window.alert(text);
const addWage = later('Выработка складывается из этапов, проведённых в рабочем листе производства; вносить её отдельно можно будет с правилами сдельной оплаты (ERP-055, решение D-06).');
const tariffLater = later('Тарифная сетка меняется вместе с правилами сдельной оплаты (ERP-055, решение D-06).');
function goalLater(ev: Event, s: Staff): void {
    (ev.target as HTMLInputElement).value = String(s.goal);
    window.alert('План выработки сохраняется вместе с правилами сдельной оплаты (ERP-055, решение D-06).');
}

const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const wsh = (id: string) => PIECE_WORKSHOPS.find((w) => w.id === id);
// Legacy PIECE_STAGES (L17462).
const STAGES: Record<string, string> = { prep: 'Подготовка', machine: 'Обработка', cover: 'Обивка/Покрытие', finish: 'Финиш', pack: 'Упаковка' };

// ── Filters (legacy pw-filter-*): the current month and year by default; the year of now and the year before ──
const curYear = +props.today.slice(0, 4);
const f = reactive({ month: String(+props.today.slice(5, 7)), year: String(curYear), act: 'all', ws: 'all' });
const categories = computed(() => [...new Set(props.categories.filter(Boolean))].sort());
const monthPrefix = computed(() => `${f.year}-${String(f.month).padStart(2, '0')}`);
const itemName = (e: Entry) => '№' + e.pos + ' · ' + e.name;

const rows = computed(() => {
    const entries = props.entries.filter((e) => {
        if (!e.date.startsWith(monthPrefix.value)) return false;
        if (f.ws !== 'all' && e.workshop !== f.ws) return false;
        if (f.act !== 'all' && e.category !== f.act) return false;
        return true;
    });
    return props.staff
        .map((emp) => {
            const own = entries.filter((e) => e.exec === emp.name);
            return { emp, ops: own.length, earned: own.reduce((s, e) => s + e.amount, 0), workshops: [...new Set(own.map((e) => e.workshop).filter(Boolean))] };
        })
        .sort((a, b) => b.earned - a.earned)
        .map((r) => {
            const pct = r.emp.goal > 0 ? Math.min(100, Math.round((r.ops / r.emp.goal) * 100)) : 0;
            const ws = wsh(r.emp.ws);
            return {
                ...r,
                pct,
                bar: pct >= 85 ? 'var(--green)' : pct >= 60 ? 'var(--accent)' : pct >= 40 ? 'var(--amber)' : 'var(--red)',
                wsLabel: r.workshops.length
                    ? r.workshops
                          .map((id) => {
                              const w = wsh(id);
                              return w ? w.icon + ' ' + w.name : id;
                          })
                          .join(', ')
                    : r.emp.ws
                      ? ws
                          ? ws.icon + ' ' + ws.name
                          : r.emp.ws
                      : '—',
            };
        });
});
const kpi = computed(() => ({
    fot: rows.value.reduce((s, r) => s + r.earned, 0),
    ops: rows.value.reduce((s, r) => s + r.ops, 0),
    best: rows.value.reduce((m, r) => (r.earned > m ? r.earned : m), 0),
    goals: rows.value.filter((r) => r.ops >= r.emp.goal).length,
}));

// ── Detail of an employee (legacy showPieceDetail): the month chosen at the click, kept until the next click ──
const detail = reactive<{ open: boolean; name: string; label: string; items: Entry[] }>({ open: false, name: '', label: '', items: [] });
const detailEl = ref<HTMLElement | null>(null);
function showDetail(s: Staff): void {
    const prefix = monthPrefix.value;
    Object.assign(detail, {
        open: true,
        name: s.name,
        label: PL_MONTH_LABELS[+f.month - 1] + ' ' + f.year,
        items: props.entries.filter((e) => e.exec === s.name && e.date.startsWith(prefix)).sort((a, b) => (a.date || '').localeCompare(b.date || '')),
    });
    nextTick(() => detailEl.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
}
const detailTotal = computed(() => detail.items.reduce((s, e) => s + e.amount, 0));

// ── Grid of rates (legacy renderPieceTariffs): by workshop in the order of the list ──
const tariffGroups = computed(() => {
    const by = new Map<string, typeof TARIFFS>();
    TARIFFS.forEach((t) => {
        const ws = t.workshop || 'other';
        if (!by.has(ws)) by.set(ws, []);
        by.get(ws)!.push(t);
    });
    return [...by.entries()].map(([id, ops]) => ({ ws: wsh(id) || { icon: '🏭', name: id }, ops }));
});
</script>

<template>
    <ErpLayout>
        <div id="page-wages" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">Сдельная оплата</div>
                    <div class="section-sub">Выработка и начисления производственного персонала</div>
                </div>
                <button class="btn btn-primary btn-sm" @click="addWage">+ Внести выработку</button>
            </div>

            <!-- KPI -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px">
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">💰 ФОТ сдельный</div>
                    <div id="pw-kpi-fot" style="font-size: 22px; font-weight: 700; color: var(--accent)">{{ fmt(kpi.fot) }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">📦 Всего операций</div>
                    <div id="pw-kpi-ops" style="font-size: 22px; font-weight: 700">{{ kpi.ops.toLocaleString('ru-RU') }} шт</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">🏆 Лучший заработок</div>
                    <div id="pw-kpi-best" style="font-size: 22px; font-weight: 700; color: var(--green)">{{ fmt(kpi.best) }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">✅ Выполнили план</div>
                    <div id="pw-kpi-goal" style="font-size: 22px; font-weight: 700">{{ kpi.goals }}/{{ props.staff.length }}</div>
                </div>
            </div>

            <!-- Rating -->
            <div class="card" style="padding: 0; margin-bottom: 16px">
                <div style="padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border)">
                    <span style="font-weight: 600">Рейтинг сотрудников</span>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap">
                        <select id="pw-filter-month" v-model="f.month" style="font-size: 12px">
                            <option v-for="(m, i) in PL_MONTH_LABELS" :key="m" :value="String(i + 1)">{{ m }}</option>
                        </select>
                        <select id="pw-filter-year" v-model="f.year" style="font-size: 12px">
                            <option v-for="y in [curYear, curYear - 1]" :key="y" :value="String(y)">{{ y }}</option>
                        </select>
                        <select id="pw-filter-act" v-model="f.act" style="font-size: 12px">
                            <option value="all">Все категории</option>
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <select id="pw-filter-ws" v-model="f.ws" style="font-size: 12px">
                            <option value="all">Все цеха</option>
                            <option value="paint">🎨 Малярка</option>
                            <option value="cut">✂️ Столярка/Раскройный</option>
                            <option value="grind">🔧 Шлифовка</option>
                            <option value="assemble">🔩 Сборка</option>
                            <option value="upholstery">🧵 Обивочный</option>
                            <option value="veneer">🪵 Шпонирование</option>
                            <option value="pack">📦 Упаковка</option>
                        </select>
                    </div>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 32px">#</th>
                            <th>Сотрудник</th>
                            <th>Цех / Вид деятельности</th>
                            <th style="text-align: right">Операций (шт)</th>
                            <th style="text-align: right">План</th>
                            <th style="min-width: 120px">Прогресс</th>
                            <th style="text-align: right">Начислено</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="piece-wages-tbody">
                        <tr v-for="(r, idx) in rows" :key="r.emp.name" style="cursor: pointer" @click="showDetail(r.emp)">
                            <td style="font-family: monospace; color: var(--text3); font-size: 12px">{{ idx + 1 }}</td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px">
                                    <div
                                        style="
                                            width: 28px;
                                            height: 28px;
                                            border-radius: 50%;
                                            background: rgba(79, 142, 247, 0.15);
                                            color: var(--accent);
                                            display: flex;
                                            align-items: center;
                                            justify-content: center;
                                            font-size: 10px;
                                            font-weight: 600;
                                            flex-shrink: 0;
                                        "
                                    >
                                        {{ initials(r.emp.name) }}
                                    </div>
                                    <div>
                                        <div style="font-weight: 500">{{ r.emp.name }}</div>
                                        <div style="font-size: 11px; color: var(--text3)">{{ r.emp.pos }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 12px; font-weight: 500">{{ r.wsLabel }}</div>
                            </td>
                            <td style="text-align: right; font-family: monospace; font-weight: 600">{{ r.ops.toLocaleString('ru-RU') }} шт</td>
                            <td style="text-align: right">
                                <input
                                    type="number"
                                    min="0"
                                    :value="r.emp.goal"
                                    style="width: 70px; font-size: 12px; text-align: right"
                                    @click.stop
                                    @change.stop="goalLater($event, r.emp)"
                                />
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px">
                                    <div style="flex: 1; height: 8px; background: var(--bg3); border-radius: 4px; overflow: hidden">
                                        <div :style="{ height: '100%', width: r.pct + '%', background: r.bar, borderRadius: '4px' }"></div>
                                    </div>
                                    <span :style="{ fontSize: '11px', fontWeight: 600, color: r.bar, minWidth: '34px' }">{{ r.pct }}%</span>
                                </div>
                            </td>
                            <td style="text-align: right; font-family: monospace; font-weight: 700; color: var(--green)">{{ fmt(r.earned) }}</td>
                            <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click.stop="showDetail(r.emp)">📋</button></td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="8" style="text-align: center; padding: 20px; color: var(--text3)">
                                Нет сотрудников производства (Тип = "Производственный" в карточке сотрудника)
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Detail of an employee -->
            <div id="pw-detail" ref="detailEl" class="card" :style="{ display: detail.open ? '' : 'none', padding: 0, marginBottom: '16px' }">
                <div style="padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center">
                    <span id="pw-detail-title" style="font-weight: 600"
                        >📋 {{ detail.name }} <span style="font-size: 11px; color: var(--text3); margin-left: 8px">{{ detail.label }}</span></span
                    >
                    <button class="btn btn-ghost btn-sm" @click="detail.open = false">✕</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Операция</th>
                            <th>Заказ</th>
                            <th style="text-align: right">Кол-во</th>
                            <th style="text-align: right">Ставка ₸/шт</th>
                            <th style="text-align: right; color: var(--green)">Сумма</th>
                        </tr>
                    </thead>
                    <tbody id="pw-detail-tbody">
                        <tr style="font-size: 11px; background: var(--bg3)">
                            <th style="padding: 6px 12px">Дата</th>
                            <th style="padding: 6px 12px">Операция</th>
                            <th style="padding: 6px 12px">Цех</th>
                            <th style="padding: 6px 12px">Заказ</th>
                            <th style="padding: 6px 12px">Изделие</th>
                            <th style="padding: 6px 12px; text-align: right">Сумма</th>
                        </tr>
                        <tr v-for="(it, i) in detail.items" :key="i">
                            <td style="font-size: 12px; color: var(--text3)">{{ it.date.split('-').reverse().join('.') }}</td>
                            <td style="font-weight: 500">{{ it.stage }}</td>
                            <td style="font-size: 12px">{{ wsh(it.workshop) ? wsh(it.workshop)!.icon + ' ' + wsh(it.workshop)!.name : '—' }}</td>
                            <td style="font-size: 12px; color: var(--accent)">{{ it.num }}</td>
                            <td style="font-size: 12px">{{ itemName(it) }}</td>
                            <td style="text-align: right; font-family: monospace; font-weight: 600; color: var(--green)">{{ fmt(it.amount) }}</td>
                        </tr>
                        <tr v-if="!detail.items.length">
                            <td colspan="6" style="text-align: center; color: var(--text3); padding: 16px">Нет проведённых операций за этот период</td>
                        </tr>
                        <tr style="font-weight: 700; background: var(--bg3)">
                            <td colspan="5" style="padding: 8px 12px">Итого начислено</td>
                            <td style="text-align: right; font-family: monospace; color: var(--green); padding: 8px 12px">{{ fmt(detailTotal) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Grid of rates -->
            <div class="card" style="padding: 0">
                <div style="padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border)">
                    <span style="font-weight: 600">⚙️ Тарифная сетка</span>
                    <button class="btn btn-ghost btn-sm" @click="tariffLater">+ Операция</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Операция</th>
                            <th>Этап</th>
                            <th style="text-align: center">Разряд</th>
                            <th style="text-align: right">Ставка (₸/шт)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="piece-tariff-tbody">
                        <template v-for="g in tariffGroups" :key="g.ws.name">
                            <tr style="background: var(--bg2)">
                                <td colspan="5" style="padding: 8px 12px; font-weight: 700; font-size: 12px; color: var(--accent)">{{ g.ws.icon }} {{ g.ws.name }}</td>
                            </tr>
                            <tr v-for="t in g.ops" :key="t.op">
                                <td style="padding: 7px 12px 7px 24px; font-weight: 500">{{ t.op }}</td>
                                <td style="padding: 7px 12px; font-size: 12px; color: var(--text3)">{{ STAGES[t.stage] || '—' }}</td>
                                <td style="padding: 7px 12px; text-align: center; font-size: 12px; color: var(--text3)">{{ t.grade }}р</td>
                                <td style="padding: 7px 12px; text-align: right; font-family: monospace; font-weight: 600; color: var(--green)">
                                    ₸{{ t.rate.toLocaleString('ru-RU') }}
                                </td>
                                <td style="padding: 7px 12px"><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="tariffLater">✕</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </ErpLayout>
</template>
