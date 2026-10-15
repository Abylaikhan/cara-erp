import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
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

// ERP-072, AC-72: a failed request never looks like a success, and the user is told why. Validation errors stay with their
// forms (onError); any other refusal of the server and a lost connection are told here, as legacy told its errors (alert).
// The page and what was typed stay as they are, so the action can be repeated. Inertia would show the server's English error
// page in a frame, and say nothing at all when the connection is lost.
let method = 'get';
router.on('start', (event) => {
    method = event.detail.visit.method;
});
const notSaved = () => (method === 'get' ? 'Данные не загружены.' : 'Изменения не сохранены — введённое осталось на экране, действие можно повторить.');
router.on('httpException', (event) => {
    const status = event.detail.response.status;
    const reason =
        status === 419 ? 'Сессия истекла — обновите страницу и войдите снова.'
        : status === 403 ? 'Нет доступа к этому действию.'
        : status === 404 ? 'Запись или страница не найдена — обновите страницу.'
        : status === 413 ? 'Слишком большой запрос (файл).'
        : status === 429 ? 'Слишком много попыток — подождите минуту.'
        : status >= 500 ? 'Ошибка на сервере.'
        : 'Сервер отклонил запрос.';
    window.alert(`${reason} (HTTP ${status})\n\n${notSaved()}`);
    return false;
});
router.on('networkError', () => {
    window.alert(`Нет связи с сервером.\n\n${notSaved()}\nПроверьте соединение и повторите.`);
    return false;
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
