<?php

/*
| PromTech ERP — pages, menu and role templates.
|
| Source: legacy index.html @ c5e745c — sidebar markup (L2006–2143), page titles map `titles` in showPage (L16330)
| and role templates `_USERS` (L1553–1595). Passwords and personal names of the legacy templates are NOT carried
| over (AUTH-01). The role → pages matrix reproduces the observed legacy behaviour and is PROVISIONAL until
| business decisions DEC-13 / DEC-15 (docs/adr/0001-provisional-role-matrix.md).
*/

return [

    'business_timezone' => env('APP_BUSINESS_TIMEZONE', 'Asia/Almaty'),

    // Menu sections in legacy order. `title` is the topbar text; legacy has no title for some pages and shows the raw id,
    // here the menu label is used instead (docs/adr/0002-ui-deviations.md, item 4).
    'sections' => [
        ['label' => 'Главная', 'pages' => ['dashboard']],
        ['label' => 'Операции', 'pages' => ['mrp', 'warehouse', 'purchases', 'esf', 'suppliers']],
        ['label' => 'Продажи', 'pages' => ['catalog', 'sales', 'customers', 'crm']],
        ['label' => 'Управление', 'pages' => ['finance', 'money', 'channels', 'expense_cats', 'money_salon', 'reconcile', 'salon_report', 'hr', 'wages', 'payroll', 'accruals', 'mycabinet']],
        ['label' => 'AI', 'pages' => ['ai']],
    ],

    'pages' => [
        'dashboard' => ['label' => 'Дашборд', 'title' => 'Дашборд', 'module' => 'M01',
            'icon' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>'],
        'mrp' => ['label' => 'Производство', 'title' => 'Производство (MRP)', 'module' => 'M02',
            'icon' => '<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>'],
        'warehouse' => ['label' => 'Склад', 'title' => 'Склад', 'module' => 'M03',
            'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/>'],
        'purchases' => ['label' => 'Закупки', 'title' => 'Закупки', 'module' => 'M04',
            'icon' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>'],
        'esf' => ['label' => 'Входящие ЭСФ', 'title' => 'Входящие ЭСФ', 'module' => 'M05',
            'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10,9 9,9 8,9"/>'],
        'suppliers' => ['label' => 'Поставщики', 'title' => 'Поставщики', 'module' => 'M06',
            'icon' => '<rect x="1" y="3" width="15" height="13"/><polygon points="16,8 20,8 23,11 23,16 16,16"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>'],
        'catalog' => ['label' => 'Каталог', 'title' => 'Каталог продукции', 'module' => 'M07',
            'icon' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>'],
        'sales' => ['label' => 'Продажи', 'title' => 'Продажи', 'module' => 'M09',
            'icon' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
        'customers' => ['label' => 'Покупатели', 'title' => 'Покупатели', 'module' => 'M08',
            'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
        'crm' => ['label' => 'CRM', 'title' => 'CRM', 'module' => 'M10',
            'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
        'finance' => ['label' => 'Финансы', 'title' => 'Финансы — P&L', 'module' => 'M11',
            'icon' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
        'money' => ['label' => 'Деньги', 'title' => 'Деньги', 'module' => 'M12',
            'icon' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>'],
        'channels' => ['label' => 'Центры прибыли', 'title' => 'Центры прибыли', 'module' => 'M13',
            'icon' => '<circle cx="12" cy="12" r="2"/><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>'],
        'expense_cats' => ['label' => 'Статьи расходов', 'title' => 'Статьи расходов', 'module' => 'M14',
            'icon' => '<path d="M4 6h16M4 10h16M4 14h10M4 18h6"/>'],
        'money_salon' => ['label' => 'Касса салона', 'title' => 'Касса салона', 'module' => 'M15',
            'icon' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M8 7V5a2 2 0 0 0-4 0v2"/><line x1="12" y1="12" x2="12" y2="16"/>'],
        'reconcile' => ['label' => 'Сверка с офисом', 'title' => 'Сверка с офисом', 'module' => 'M16',
            'icon' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'],
        'salon_report' => ['label' => 'Отчёт салонов', 'title' => 'Отчёт салонов', 'module' => 'M21',
            'icon' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>'],
        'hr' => ['label' => 'Сотрудники', 'title' => 'Сотрудники', 'module' => 'M17',
            'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
        'wages' => ['label' => 'Сдельная оплата', 'title' => 'Сдельная оплата', 'module' => 'M18',
            'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>'],
        'payroll' => ['label' => 'Зарплатная ведомость', 'title' => 'Зарплатная ведомость', 'module' => 'M19',
            'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
        'accruals' => ['label' => 'Начисления ЗП', 'title' => 'Начисления ЗП', 'module' => 'M20',
            'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'],
        'mycabinet' => ['label' => 'Мой кабинет', 'title' => 'Личный кабинет', 'module' => 'M22',
            'icon' => '<path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/><polygon points="18,2 22,6 12,16 8,16 8,12"/>'],
        'ai' => ['label' => 'AI Ассистент', 'title' => 'AI Ассистент', 'module' => 'M23',
            'icon' => '<path d="M12 2a4 4 0 0 1 4 4 4 4 0 0 1-4 4 4 4 0 0 1-4-4 4 4 0 0 1 4-4z"/><path d="M12 10v4M8 14h8M6 18v-1a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/>'],
    ],

    // Legacy role templates (_USERS). `techcards` is listed for director in legacy but has no page (D-15): it stays
    // in the template for traceability and is ignored by navigation and access checks.
    'roles' => [
        'director' => ['label' => 'Директор', 'color' => '#4f8ef7',
            'pages' => ['dashboard', 'mrp', 'warehouse', 'purchases', 'esf', 'suppliers', 'catalog', 'sales', 'customers', 'crm', 'finance', 'money', 'channels', 'expense_cats', 'reconcile', 'hr', 'wages', 'payroll', 'accruals', 'techcards', 'mycabinet', 'ai']],
        'manager' => ['label' => 'Бухгалтер (продажи)', 'color' => '#3dd68c',
            'pages' => ['dashboard', 'catalog', 'sales', 'customers', 'crm', 'mycabinet']],
        'storekeeper' => ['label' => 'Кладовщик', 'color' => '#f5a623',
            'pages' => ['dashboard', 'warehouse', 'purchases', 'suppliers', 'mycabinet']],
        'foreman' => ['label' => 'Нач. цеха', 'color' => '#a78bfa',
            'pages' => ['dashboard', 'mrp', 'warehouse', 'wages', 'mycabinet']],
        'accountant' => ['label' => 'Гл. бухгалтер', 'color' => '#f43f5e',
            'pages' => ['dashboard', 'finance', 'money', 'channels', 'reconcile', 'hr', 'wages', 'sales', 'customers', 'mycabinet']],
        'hr' => ['label' => 'Бухгалтер/HR', 'color' => '#10b981',
            'pages' => ['dashboard', 'hr', 'wages', 'mycabinet']],
        'buyer' => ['label' => 'Закупщик', 'color' => '#f59e0b',
            'pages' => ['dashboard', 'purchases', 'suppliers', 'warehouse', 'mycabinet']],
        'salon_armada' => ['label' => 'Салон Армада', 'color' => '#8b5cf6', 'channel' => 'salon_armada', 'is_manager' => false,
            'pages' => ['catalog', 'sales', 'crm', 'money_salon'],
            'flags' => ['can_create_orders' => true, 'can_cash_in' => true, 'can_view_rs' => true, 'can_transfer_to_office' => true]],
        'salon_nazarbayeva' => ['label' => 'Салон Назарбаева', 'color' => '#ec4899', 'channel' => 'salon_nazarbayeva', 'is_manager' => false,
            'pages' => ['catalog', 'sales', 'crm', 'money_salon'],
            'flags' => ['can_create_orders' => true, 'can_cash_in' => true, 'can_view_rs' => true, 'can_transfer_to_office' => true]],
        'salon_armada_mgr' => ['label' => 'Начальник салонов', 'color' => '#8b5cf6', 'channel' => null, 'is_manager' => true,
            'pages' => ['catalog', 'sales', 'crm', 'money_salon', 'salon_report', 'hr'],
            'flags' => ['can_create_orders' => true, 'can_cash_in' => true, 'can_view_rs' => true]],
        'dealer_astana' => ['label' => 'Дилер — Астана', 'color' => '#06b6d4', 'channel' => 'dealer_astana',
            'pages' => ['catalog', 'sales', 'reconcile'], 'flags' => ['can_create_orders' => true, 'can_cash_in' => false, 'can_view_rs' => false]],
        'dealer_aktau' => ['label' => 'Дилер — Актау', 'color' => '#06b6d4', 'channel' => 'dealer_aktau',
            'pages' => ['catalog', 'sales', 'reconcile'], 'flags' => ['can_create_orders' => true, 'can_cash_in' => false, 'can_view_rs' => false]],
        'dealer_shymkent' => ['label' => 'Дилер — Шымкент', 'color' => '#06b6d4', 'channel' => 'dealer_shymkent',
            'pages' => ['catalog', 'sales', 'reconcile'], 'flags' => ['can_create_orders' => true, 'can_cash_in' => false, 'can_view_rs' => false]],
        'dealer_atyrau' => ['label' => 'Дилер — Атырау', 'color' => '#06b6d4', 'channel' => 'dealer_atyrau',
            'pages' => ['catalog', 'sales', 'reconcile'], 'flags' => ['can_create_orders' => true, 'can_cash_in' => false, 'can_view_rs' => false]],
        'dealer_aktobe' => ['label' => 'Дилер — Актобе', 'color' => '#06b6d4', 'channel' => 'dealer_aktobe',
            'pages' => ['catalog', 'sales', 'reconcile'], 'flags' => ['can_create_orders' => true, 'can_cash_in' => false, 'can_view_rs' => false]],
    ],

    // Tables/columns that reference expense categories by id. Merge rewrites all of them in one transaction (QA-062).
    // Modules add their references here when they are migrated (money operations, advance reports, ESF).
    'expense_category_references' => [
        ['table' => 'suppliers', 'column' => 'expense_category_id'], // M06: the expense item of the supplier card
    ],

    // Tables/columns that reference suppliers by id. «Объединить» moves all of them to the kept supplier in one transaction.
    // Modules add their references here when they are migrated (money operations, ESF, purchases).
    'supplier_references' => [
        ['table' => 'material_aliases', 'column' => 'supplier_id'], // M03: the supplier of a material alias
        ['table' => 'incoming_invoices', 'column' => 'supplier_id'], // M05: the seller of an incoming ESF
    ],

    // CRM (M10). Lead statuses and sources: legacy LEAD_STATUSES / LEAD_SOURCES (L31463); segments: segmentsCatalog (L28812).
    'lead_statuses' => [
        'new' => ['label' => '🆕 Новый', 'color' => 'var(--text3)'],
        'contact' => ['label' => '📞 Контакт', 'color' => 'var(--accent)'],
        'meeting' => ['label' => '🤝 Встреча', 'color' => '#8b5cf6'],
        'qualify' => ['label' => '🔍 Квалификация', 'color' => '#0ea5e9'],
        'proposal' => ['label' => '📄 КП отправлено', 'color' => '#f59e0b'],
        'negotiation' => ['label' => '🤝 Переговоры', 'color' => '#f97316'],
        'won' => ['label' => '✅ Выиграли', 'color' => 'var(--green)'],
        'lost' => ['label' => '❌ Проиграли', 'color' => 'var(--red)'],
    ],
    'lead_sources' => ['instagram' => 'Instagram', 'referral' => 'Рекомендация', 'walk-in' => 'Зашёл сам', 'call' => 'Звонок', 'site' => 'Сайт', 'other' => 'Другое'],
    'lead_segments' => [
        1 => ['label' => 'Новый', 'color' => '#4f8ef7'],
        2 => ['label' => 'Активный', 'color' => '#3dd68c'],
        3 => ['label' => 'Потенциальный крупный', 'color' => '#a78bfa'],
        4 => ['label' => 'Розница', 'color' => '#f5a623'],
        5 => ['label' => 'Опт', 'color' => '#38bdf8'],
        6 => ['label' => 'Спящий', 'color' => '#94a3b8'],
        7 => ['label' => 'VIP', 'color' => '#f43f5e'],
    ],
    // Columns of «Воронка лидов». Legacy uses CRM_STAGES without defining it, so its board never renders; the stages are the
    // statuses its lead form marks «→ воронка», and «Контакт»/«Встреча» go to «Квалификация» as its loader maps them.
    'funnel_stages' => [
        'qualify' => ['label' => '🔍 Квалификация', 'statuses' => ['contact', 'meeting', 'qualify']],
        'proposal' => ['label' => '📄 КП отправлено', 'statuses' => ['proposal']],
        'negotiation' => ['label' => '🤝 Переговоры', 'statuses' => ['negotiation']],
    ],
    // Legacy CRM_STAGE_ORDER (L29285): «→ Следующий этап» of the lead card moves a lead along it; «Лид» is a new lead.
    'stage_order' => ['new', 'qualify', 'proposal', 'negotiation', 'won', 'lost'],
    // Task priorities as the legacy task form stores them (its PRIORITY_CONFIG names «normal» «medium»), communication types:
    // COMM_TYPES (L29386).
    'task_priorities' => ['high', 'normal', 'low'],
    'communication_types' => ['call', 'letter', 'meeting', 'whatsapp', 'email', 'note'],

    // Виды деятельности (legacy WORKSHOPS, L27195): production lines that staff and write-offs are bound to.
    'workshops' => [
        ['id' => 'act_chair', 'name' => 'Стул', 'icon' => '🪑'],
        ['id' => 'act_table', 'name' => 'Стол', 'icon' => '🪞'],
        ['id' => 'act_bed', 'name' => 'Кровать', 'icon' => '🛏'],
        ['id' => 'act_soft', 'name' => 'Мягкая мебель', 'icon' => '🛋'],
        ['id' => 'act_cabinet', 'name' => 'Корпусная мебель', 'icon' => '🗄'],
    ],
];
