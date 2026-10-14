<?php

/*
| Read-only copy of the legacy Supabase (PostgREST) into the local environment.
| URL and anon key come from .env (LEGACY_SUPABASE_URL, LEGACY_SUPABASE_ANON_KEY) and are never committed.
| Tables: every table the legacy index.html @ c5e745c reads or writes (static inventory, 38 names).
*/

return [

    'supabase' => [
        'url' => env('LEGACY_SUPABASE_URL'),
        'key' => env('LEGACY_SUPABASE_ANON_KEY'),
        'timeout' => 60,
    ],

    'page_size' => 1000,
    'pause_ms' => 100,
    'attempts' => 3,
    'retry_sleep_s' => 2,

    // Snapshots contain personal data: they stay in the git-ignored .local-audit/ with 0700 permissions.
    'snapshot_dir' => base_path('.local-audit/supabase'),

    // Stable pagination needs an order; the first column the table accepts is used.
    'order_candidates' => ['id', 'code', 'num', 'item_key', 'created_at'],

    // Credentials are not copied: the new system never reuses legacy secrets (ТЗ AUTH-01, D-13).
    'redact_columns' => '/^(password|pass|pwd|pin|pin_code|secret|token|api_key|apikey|access_token|refresh_token)$/i',

    'tables' => [
        'advance_reports',
        'app_users',
        'bank_statement_raw',
        'customers',
        'esf_payment_links',
        'expense_categories',
        'fixed_costs',
        'goods_receipts',
        'incoming_invoice_items',
        'incoming_invoices',
        'leads',
        'material_aliases',
        'material_name_aliases',
        'material_requests',
        'money_tx',
        'order_items',
        'order_payments',
        'orders',
        'payment_history',
        'production_progress',
        'production_worksheet',
        'products',
        'project_calcs',
        'project_comments',
        'project_files',
        'projects',
        'purch_orders',
        'realizations',
        'restoration_files',
        'restorations',
        'salary_accruals',
        'salary_accruals_history',
        'sales_worksheet',
        'salon_payroll',
        'salon_worksheet',
        'staff',
        'staff_changes',
        'staff_salary_history',
        'suppliers',
        'tasks',
        'tech_cards',
        'wage_items',
        'warehouse',
        'wh_documents',
    ],
];
