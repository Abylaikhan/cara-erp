<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\CrmTaskController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DealerController;
use App\Http\Controllers\EsfController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\LeadCommunicationController;
use App\Http\Controllers\MoneyController;
use App\Http\Controllers\MrpController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SalonCashController;
use App\Http\Controllers\SalonWorksheetController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\WarehouseController;
use App\Support\Erp;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::post('/login/pin', [LoginController::class, 'pin']);
});

Route::middleware('auth')->group(function () {
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // M14 «Статьи расходов»
    Route::middleware('page:expense_cats')->prefix('expense_cats')->name('expense-cats.')->controller(ExpenseCategoryController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/merge', 'merge')->name('merge');
        Route::put('/{category}', 'update')->whereNumber('category')->name('update');
        Route::delete('/{category}', 'destroy')->whereNumber('category')->name('destroy');
    });

    // M07 «Каталог»
    Route::middleware('page:catalog')->prefix('catalog')->name('catalog.')->controller(ProductController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{product}', 'update')->whereNumber('product')->name('update');
        Route::delete('/{product}', 'destroy')->whereNumber('product')->name('destroy');
    });

    // M03 «Склад»: справочник материалов и алиасы (документы склада — ERP-039/040)
    Route::middleware('page:warehouse')->prefix('warehouse')->name('warehouse.')->controller(WarehouseController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/materials', 'storeMaterial')->name('materials.store');
        Route::put('/materials/{material}', 'updateMaterial')->whereNumber('material')->name('materials.update');
        Route::delete('/materials/{material}', 'destroyMaterial')->whereNumber('material')->name('materials.destroy');
        Route::post('/aliases', 'storeAlias')->name('aliases.store');
        Route::delete('/aliases/{alias}', 'destroyAlias')->whereNumber('alias')->name('aliases.destroy');
    });

    // M05 «Входящие ЭСФ»: список, детализация, тип, импорт XML (сверка с оплатами — ERP-048)
    Route::middleware('page:esf')->prefix('esf')->name('esf.')->controller(EsfController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{invoice}/detail', 'detail')->whereNumber('invoice')->name('detail');
        Route::put('/{invoice}/type', 'setType')->whereNumber('invoice')->name('type');
        Route::post('/import/preview', 'preview')->name('import.preview');
        Route::post('/import', 'import')->name('import');
    });

    // M06 «Поставщики»
    Route::middleware('page:suppliers')->prefix('suppliers')->name('suppliers.')->controller(SupplierController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/merge', 'merge')->name('merge');
        Route::post('/import', 'import')->name('import');
        Route::put('/{supplier}', 'update')->whereNumber('supplier')->name('update');
        Route::delete('/{supplier}', 'destroy')->whereNumber('supplier')->name('destroy');
    });

    // M10 CRM: лиды, воронка, задачи, история коммуникаций (клиенты и доски заказов — с переносом заказов)
    Route::middleware('page:crm')->prefix('crm')->name('crm.')->controller(CrmController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/leads', 'store')->name('leads.store');
        Route::put('/leads/{lead}', 'update')->whereNumber('lead')->name('leads.update');
        Route::put('/leads/{lead}/status', 'status')->whereNumber('lead')->name('leads.status');
        Route::post('/leads/{lead}/client', 'client')->whereNumber('lead')->name('leads.client');
        Route::delete('/leads/{lead}', 'destroy')->whereNumber('lead')->name('leads.destroy');
        Route::get('/leads/{lead}/communications', [LeadCommunicationController::class, 'index'])->whereNumber('lead')->name('communications.index');
        Route::post('/leads/{lead}/communications', [LeadCommunicationController::class, 'store'])->whereNumber('lead')->name('communications.store');
        Route::delete('/communications/{communication}', [LeadCommunicationController::class, 'destroy'])->whereNumber('communication')->name('communications.destroy');
        Route::post('/tasks', [CrmTaskController::class, 'store'])->name('tasks.store');
        Route::put('/tasks/{task}', [CrmTaskController::class, 'update'])->whereNumber('task')->name('tasks.update');
        Route::put('/tasks/{task}/toggle', [CrmTaskController::class, 'toggle'])->whereNumber('task')->name('tasks.toggle');
        Route::delete('/tasks/{task}', [CrmTaskController::class, 'destroy'])->whereNumber('task')->name('tasks.destroy');
        // «Проекты» (ERP-079): запросы на расчёт нестандартной мебели
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->whereNumber('project')->name('projects.update');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->name('projects.destroy');
        Route::post('/projects/{project}/executors', [ProjectController::class, 'addExecutor'])->whereNumber('project')->name('projects.executors.store');
        Route::delete('/projects/{project}/executors/{login}', [ProjectController::class, 'removeExecutor'])->whereNumber('project')->where('login', '[A-Za-z0-9_.@-]+')->name('projects.executors.destroy');
        Route::post('/projects/{project}/calcs', [ProjectController::class, 'saveCalc'])->whereNumber('project')->name('projects.calcs.store');
        Route::post('/projects/{project}/calcs/send', [ProjectController::class, 'sendCalc'])->whereNumber('project')->name('projects.calcs.send');
        Route::post('/projects/{project}/decision', [ProjectController::class, 'decide'])->whereNumber('project')->name('projects.decide');
        Route::post('/projects/{project}/comments', [ProjectController::class, 'comment'])->whereNumber('project')->name('projects.comments.store');
        Route::post('/projects/{project}/files', [ProjectController::class, 'upload'])->whereNumber('project')->name('projects.files.store');
        Route::get('/projects/files/{file}', [ProjectController::class, 'file'])->whereNumber('file')->name('projects.files.show');
        Route::delete('/projects/files/{file}', [ProjectController::class, 'deleteFile'])->whereNumber('file')->name('projects.files.destroy');
    });

    // M01 «Дашборд»: сводка по перенесённым данным (просмотр)
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('page:dashboard')->name('dashboard');

    // M15 «Касса салона», часть 1: операции своей кассы (просмотр)
    Route::get('/money_salon', [SalonCashController::class, 'index'])->middleware('page:money_salon')->name('money-salon.index');

    // M11 «Финансы», часть 1: дебиторка и ДДС (просмотр)
    Route::get('/finance', [FinanceController::class, 'index'])->middleware('page:finance')->name('finance.index');

    // M02 «Производство», часть 1: журнал производственных позиций (просмотр)
    Route::get('/mrp', [MrpController::class, 'index'])->middleware('page:mrp')->name('mrp.index');

    // M12 «Деньги», часть 1: кассы, счета, все операции (просмотр)
    Route::get('/money', [MoneyController::class, 'index'])->middleware('page:money')->name('money.index');
    Route::middleware('page:money')->prefix('money/salon-worksheet')->name('money.salon-worksheet.')->controller(SalonWorksheetController::class)->group(function () {
        Route::post('/', 'store')->name('store');
        Route::post('/clear', 'clear')->name('clear');
        Route::put('/{row}', 'update')->whereNumber('row')->name('update');
        Route::delete('/{row}', 'destroy')->whereNumber('row')->name('destroy');
    });

    // M04 «Закупки»: заявки на материал (поступления — с документами склада, нормы — с технологическими картами)
    Route::middleware('page:purchases')->prefix('purchases')->name('purchases.')->controller(PurchaseController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/requests', 'store')->name('requests.store');
        Route::put('/requests/{materialRequest}/status', 'status')->whereNumber('materialRequest')->name('requests.status');
    });

    // M09 «Продажи», часть 1: журнал заказов (просмотр); заказы создаются и меняются с ERP-029/030
    Route::middleware('page:sales')->prefix('sales')->name('sales.')->controller(SalesController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/orders/{order}/complete', 'complete')->whereNumber('order')->name('orders.complete');
    });

    // M08 «Покупатели» — tab «Контрагенты»
    Route::middleware('page:customers')->prefix('customers')->name('customers.')->controller(CustomerController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{customer}', 'update')->whereNumber('customer')->name('update');
    });
    // M08 «Покупатели» — tab «Дилеры»: a dealer is a sales channel of the group «dealer»
    Route::middleware('page:customers')->prefix('customers/dealers')->name('customers.dealers.')->controller(DealerController::class)->group(function () {
        Route::post('/', 'store')->name('store');
        Route::post('/import', 'import')->name('import');
        Route::put('/{code}', 'update')->where('code', '[a-z][a-z0-9_]*')->name('update');
        Route::delete('/{code}', 'destroy')->where('code', '[a-z][a-z0-9_]*')->name('destroy');
    });

    // Pages that are not migrated yet — the menu stays 1:1, the page says so honestly.
    $migrated = ['dashboard', 'finance', 'money_salon', 'expense_cats', 'catalog', 'customers', 'suppliers', 'warehouse', 'esf', 'crm', 'purchases', 'sales', 'mrp', 'money'];
    Route::get('/{page}', [PageController::class, 'notMigrated'])
        ->whereIn('page', array_values(array_diff(array_keys(Erp::pages()), $migrated)))
        ->middleware('page')->name('page');
});
