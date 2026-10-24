import type { InjectionKey, Ref } from 'vue';
import type DrilldownModal from '@/components/DrilldownModal.vue';

/**
 * The one window «Детализация» of a page (legacy has a single #drilldownModal): the page renders it and gives it to its tabs,
 * so two tabs never put two windows with the same ids on the page.
 */
export type Drilldown = Ref<InstanceType<typeof DrilldownModal> | null>;
export const DRILLDOWN: InjectionKey<Drilldown> = Symbol('drilldown');
