import '../css/app.css';
import 'primeicons/primeicons.css';

import { createInertiaApp } from '@inertiajs/vue3';
import Aura from '@primeuix/themes/aura';
import PrimeVue from 'primevue/config';

createInertiaApp({
    title: (title) => (title ? `${title} — PromTech ERP` : 'PromTech ERP'),
    withApp(app) {
        app.use(PrimeVue, {
            // Temporary styled preset for the skeleton. The legacy look will be reproduced
            // with project tokens (unstyled where needed) — see docs/adr in the analysis package.
            theme: { preset: Aura, options: { darkModeSelector: false } },
            locale: { ...ruLocale },
        });
    },
    progress: { color: '#2563eb' },
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
    passwordPrompt: 'Введите пароль',
    weak: 'Слабый',
    medium: 'Средний',
    strong: 'Сильный',
};
