<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\PageController;
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

    // Pages that are not migrated yet — the menu stays 1:1, the page says so honestly.
    $migrated = ['expense_cats'];
    Route::get('/{page}', [PageController::class, 'notMigrated'])
        ->whereIn('page', array_values(array_diff(array_keys(Erp::pages()), $migrated)))
        ->middleware('page')->name('page');
});
