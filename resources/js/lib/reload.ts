import { router } from '@inertiajs/vue3';

type ReloadOptions = NonNullable<Parameters<typeof router.reload>[0]>;
const pending: Record<string, { cancel: () => void } | undefined> = {};

/**
 * A partial reload of one prop that cancels the one still running for the same prop: when the period or the year changes
 * twice in a row (both dates typed, two years chosen quickly), the answer for the older choice can no longer land after the
 * newer one and leave its figures on the screen.
 */
export function reloadLatest(prop: string, data: Record<string, string> = {}, options: Omit<ReloadOptions, 'only' | 'data'> = {}): void {
    pending[prop]?.cancel();
    router.reload({ ...options, only: [prop], data, onCancelToken: (token) => (pending[prop] = token) });
}
