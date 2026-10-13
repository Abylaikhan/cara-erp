<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Card from 'primevue/card';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import AppLayout from '@/layouts/AppLayout.vue';

interface SystemStatus {
    laravel: string;
    php: string;
    database: {
        driver: string;
        version: string;
        name: string;
        migrations: number;
        users: number;
    };
    timezone: string;
    serverTime: string;
}

defineProps<{ status: SystemStatus }>();
</script>

<template>
    <Head title="Состояние системы" />
    <AppLayout>
        <Message severity="info" :closable="false" class="notice">
            Каркас приложения. Модули ERP переносятся поэтапно по карте требований; эта страница показывает только состояние окружения.
        </Message>

        <div class="grid">
            <Card>
                <template #title>База данных</template>
                <template #content>
                    <dl>
                        <dt>Драйвер</dt>
                        <dd><Tag :value="status.database.driver" :severity="status.database.driver === 'pgsql' ? 'success' : 'danger'" /></dd>
                        <dt>Сервер</dt>
                        <dd>PostgreSQL {{ status.database.version }}</dd>
                        <dt>База</dt>
                        <dd>{{ status.database.name }}</dd>
                        <dt>Миграций применено</dt>
                        <dd>{{ status.database.migrations }}</dd>
                        <dt>Пользователей</dt>
                        <dd>{{ status.database.users }}</dd>
                    </dl>
                </template>
            </Card>

            <Card>
                <template #title>Приложение</template>
                <template #content>
                    <dl>
                        <dt>Laravel</dt>
                        <dd>{{ status.laravel }}</dd>
                        <dt>PHP</dt>
                        <dd>{{ status.php }}</dd>
                        <dt>Часовой пояс</dt>
                        <dd>{{ status.timezone }}</dd>
                        <dt>Время сервера</dt>
                        <dd>{{ status.serverTime }}</dd>
                    </dl>
                </template>
            </Card>
        </div>
    </AppLayout>
</template>

<style scoped>
.notice {
    margin-bottom: 1rem;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
}

dl {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 0.5rem 1rem;
    margin: 0;
}

dt {
    color: var(--app-muted);
}

dd {
    margin: 0;
}
</style>
