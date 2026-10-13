import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import PrimeVue from 'primevue/config';

createInertiaApp({
    // Legacy keeps one document title for every page.
    title: () => 'PromTech ERP',
    withApp(app) {
        // Unstyled: no ready-made theme may change the legacy geometry (ТЗ UI-02).
        app.use(PrimeVue, { unstyled: true, locale: { ...ruLocale } });
    },
    // Legacy has no navigation progress bar.
    progress: false,
});

const ruLocale = {
    accept: 'Да',
    reject: 'Нет',
    choose: 'Выбрать',
    upload: 'Загрузить',
    cancel: 'Отмена',
    clear: 'Очистить',
    apply: 'Применить',
    emptyMessage: 'Нет данных',
    emptySearchMessage: 'Ничего не найдено',
    firstDayOfWeek: 1,
    dayNames: ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'],
    dayNamesShort: ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
    dayNamesMin: ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
    monthNames: ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'],
    monthNamesShort: ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'],
    today: 'Сегодня',
    weekHeader: 'Нед',
    dateFormat: 'dd.mm.yy',
};
