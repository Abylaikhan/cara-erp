<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Checkbox from 'primevue/checkbox';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Password from 'primevue/password';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Вход" />
    <div class="login">
        <Card class="login__card">
            <template #title>PromTech ERP</template>
            <template #subtitle>Вход в систему</template>
            <template #content>
                <form class="login__form" @submit.prevent="submit">
                    <label for="email">Email</label>
                    <InputText id="email" v-model="form.email" type="email" autocomplete="username" autofocus fluid :invalid="!!form.errors.email" />
                    <Message v-if="form.errors.email" severity="error" size="small" variant="simple">{{ form.errors.email }}</Message>

                    <label for="password">Пароль</label>
                    <Password input-id="password" v-model="form.password" :feedback="false" toggle-mask autocomplete="current-password" fluid :invalid="!!form.errors.password" />
                    <Message v-if="form.errors.password" severity="error" size="small" variant="simple">{{ form.errors.password }}</Message>

                    <div class="login__remember">
                        <Checkbox input-id="remember" v-model="form.remember" binary />
                        <label for="remember">Запомнить меня</label>
                    </div>

                    <Button type="submit" label="Войти" icon="pi pi-sign-in" :loading="form.processing" fluid />
                </form>
            </template>
        </Card>
    </div>
</template>

<style scoped>
.login {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 1rem;
}

.login__card {
    width: 100%;
    max-width: 380px;
}

.login__form {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.login__remember {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0.5rem 0;
}
</style>
