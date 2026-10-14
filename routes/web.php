<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DealerController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
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

    // M06 «Поставщики»
    Route::middleware('page:suppliers')->prefix('suppliers')->name('suppliers.')->controller(SupplierController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/merge', 'merge')->name('merge');
        Route::post('/import', 'import')->name('import');
        Route::put('/{supplier}', 'update')->whereNumber('supplier')->name('update');
        Route::delete('/{supplier}', 'destroy')->whereNumber('supplier')->name('destroy');
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
    $migrated = ['expense_cats', 'catalog', 'customers', 'suppliers', 'warehouse'];
    Route::get('/{page}', [PageController::class, 'notMigrated'])
        ->whereIn('page', array_values(array_diff(array_keys(Erp::pages()), $migrated)))
        ->middleware('page')->name('page');
});
