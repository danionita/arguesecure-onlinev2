<script setup lang="ts">
/**
 * One scale's bands, editable (W65, ui.md §8).
 *
 * ## The boundary column is one field, not two
 *
 * A band's upper bound and the next band's lower bound are the same number.
 * The table renders each boundary **once**, on the row below which it falls,
 * and the row above shows its own upper bound as read-only text. There is no
 * way to type two values that disagree, which is what ui.md means by "make
 * them impossible to violate rather than validating after the fact".
 *
 * ## What the representative column is for
 *
 * It is the number every qualitative value on this scale actually resolves to,
 * so it is a first-class editable column and not a detail hidden behind a
 * disclosure. When it differs from what the rule would suggest for the current
 * bounds, the suggestion is shown beside it — a nudge, never an auto-correct:
 * a considered representative is exactly the thing a calibration workshop
 * produces, and silently overwriting it would discard the work.
 */
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Input from '@/Components/UI/Input.vue';
import { computed } from 'vue';
import {
    moveBoundary,
    removeBand,
    splitBand,
    suggestRepresentative,
    type EditableBand,
    type ScaleUnit,
} from './bands';

const props = defineProps<{
    bands: EditableBand[];
    unit: ScaleUnit;
    currency: string;
    topBandMultiplier: number;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:bands': [bands: EditableBand[]] }>();

const unitLabel = computed(() => {
    if (props.unit === 'currency') return props.currency;
    if (props.unit === 'rate_per_year') return 'per year';
    return 'fraction of risk removed';
});

function onBoundary(index: number, raw: string) {
    const value = Number(raw);
    if (Number.isNaN(value)) return;

    emit(
        'update:bands',
        moveBoundary(
            props.bands,
            index,
            value,
            props.unit,
            props.topBandMultiplier,
        ),
    );
}

/**
 * The label is the only text field left (W87). The code was removed by D44 —
 * it is internal and is derived server-side — and the colour by D45, so the
 * narrowed type here is the guard that neither comes back as an input without
 * someone reading those decisions first.
 */
function onLabel(index: number, value: string) {
    const updated = props.bands.map((band) => ({ ...band }));
    updated[index].label = value;
    emit('update:bands', updated);
}

function onRepresentative(index: number, raw: string) {
    const value = Number(raw);
    if (Number.isNaN(value)) return;

    const updated = props.bands.map((band) => ({ ...band }));
    updated[index].representative = value;
    emit('update:bands', updated);
}

function suggestionFor(band: EditableBand): number {
    return suggestRepresentative(
        props.unit,
        band.lower_bound,
        band.upper_bound,
        props.topBandMultiplier,
    );
}

/**
 * Whether the stored representative has drifted from the suggestion. A
 * tolerance rather than equality: the suggestion is a float and the stored
 * value came back through a DECIMAL(20,6) column, so exact comparison would
 * flag every untouched band as drifted.
 */
function isCustomised(band: EditableBand): boolean {
    const suggested = suggestionFor(band);
    const scale = Math.max(1, Math.abs(suggested));

    return Math.abs(band.representative - suggested) / scale > 1e-6;
}

function outOfBand(band: EditableBand): boolean {
    // Invariant 5, shown where it is caused rather than only reported by the
    // server after a save. The user can still submit — ScaleValidator remains
    // the authority — but they should not be surprised by the rejection.
    if (band.representative < band.lower_bound) return true;
    if (band.upper_bound === null) return false;

    return props.unit === 'fraction' && band.upper_bound === 1
        ? band.representative > band.upper_bound
        : band.representative >= band.upper_bound;
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr
                    class="text-text-muted border-border-subtle border-b text-left text-xs"
                >
                    <th class="py-2 pr-2 font-medium">Band</th>
                    <!--
                        No Code and no Colour column (W87, D44 and D45). The
                        code is internal and derived from the label on create;
                        the colour was read by nothing outside this screen.
                    -->
                    <th class="py-2 pr-2 font-medium">From</th>
                    <th class="py-2 pr-2 font-medium">Up to</th>
                    <th class="py-2 pr-2 font-medium">
                        Represented by
                        <span class="font-normal">({{ unitLabel }})</span>
                    </th>
                    <th class="py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(band, index) in bands"
                    :key="index"
                    class="border-border-subtle border-b align-top"
                    :data-band="index"
                >
                    <td class="py-2 pr-2">
                        <Input
                            :model-value="band.label"
                            :disabled="disabled"
                            :aria-label="`Label for band ${index + 1}`"
                            @update:model-value="
                                (v) => onLabel(index, String(v))
                            "
                        />
                    </td>
                    <!--
                        Read-only, always. The lower bound of every band except
                        the first is the boundary owned by the row above; the
                        first band's is 0 by invariant 3 and is not a choice.
                    -->
                    <td class="text-text-secondary py-3 pr-2 tabular-nums">
                        {{ band.lower_bound }}
                    </td>
                    <td class="py-2 pr-2">
                        <Input
                            v-if="index < bands.length - 1"
                            type="number"
                            :model-value="String(band.upper_bound ?? '')"
                            :disabled="disabled"
                            :aria-label="`Boundary between ${band.label} and ${bands[index + 1].label}`"
                            @update:model-value="
                                (v) => onBoundary(index, String(v))
                            "
                        />
                        <span v-else class="text-text-muted block py-1">
                            {{
                                band.upper_bound === null
                                    ? 'and above'
                                    : band.upper_bound
                            }}
                        </span>
                    </td>
                    <td class="py-2 pr-2">
                        <Input
                            type="number"
                            :model-value="String(band.representative)"
                            :disabled="disabled"
                            :aria-label="`Representative value for ${band.label}`"
                            @update:model-value="
                                (v) => onRepresentative(index, String(v))
                            "
                        />
                        <p
                            v-if="outOfBand(band)"
                            class="text-danger-text mt-1 text-xs"
                            data-testid="representative-out-of-band"
                        >
                            Must fall inside this band, or the value will not
                            map back to it.
                        </p>
                        <p
                            v-else-if="isCustomised(band)"
                            class="text-text-muted mt-1 text-xs"
                        >
                            Suggested: {{ suggestionFor(band).toPrecision(6) }}
                        </p>
                    </td>
                    <td class="py-2 whitespace-nowrap">
                        <IconButton
                            icon="plus"
                            label="Split this band in two"
                            :disabled="disabled"
                            @click="
                                emit(
                                    'update:bands',
                                    splitBand(
                                        bands,
                                        index,
                                        unit,
                                        topBandMultiplier,
                                    ),
                                )
                            "
                        />
                        <IconButton
                            icon="trash-2"
                            label="Remove this band"
                            variant="danger"
                            :disabled="disabled || bands.length <= 1"
                            @click="
                                emit(
                                    'update:bands',
                                    removeBand(
                                        bands,
                                        index,
                                        unit,
                                        topBandMultiplier,
                                    ),
                                )
                            "
                        />
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="text-text-muted mt-3 flex items-start gap-1.5 text-xs">
            <Icon name="info" :size="13" class="mt-0.5 shrink-0" />
            <span>
                A value exactly on a boundary falls in the
                <strong class="text-text-secondary">higher</strong> band — so
                with a boundary at 100,000, exactly 100,000 is the band above.
                Removing a band gives its range to its neighbour, so the scale
                always covers everything from zero upward.
            </span>
        </p>
    </div>
</template>
