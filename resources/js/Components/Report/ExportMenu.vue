<script setup lang="ts">
/**
 * Every export, in one menu (D34, W74 item 15).
 *
 * Before this there were three controls in a row — Download report, Defences
 * CSV, Export JSON — sharing the options bar with Print and Close, and the
 * bar read as a pile of buttons rather than as two decisions. It is two
 * decisions: *what shall this report say* (the options below) and *what shall
 * I take away with me* (this). Five formats behind one trigger is the second
 * one stated once.
 *
 * **"Download report" is gone (D35).** W33's argument for a no-options
 * download button was that the default path to a document should not require
 * understanding five toggles — which is now served here, where the same
 * forced-full render is one of five formats and is named by what it
 * produces. The URL it used has not gone anywhere: `?download=1` still
 * forces the whole report on every one of these links, which is what the
 * *Everything expanded* item passes.
 *
 * **Plain `<a>`, never the Button component's `href` mode.** That renders an
 * Inertia `<Link>`, which performs an XHR visit expecting an Inertia
 * response — these five routes return a file with
 * `Content-Disposition: attachment`, which an Inertia visit mishandles (no
 * `X-Inertia` response header) and which ends with nothing happening and no
 * error.
 *
 * The current options ride along in the query string, so an export means
 * what the panel says it means: the file a reader takes away is the document
 * they were looking at, not a differently-configured one. That binding is
 * the whole of W33's finding about the old download button, and it is
 * asserted server-side as well as here.
 */
import type { ReportOptions } from '@/Components/Report/types';
import Button from '@/Components/UI/Button.vue';
import Dropdown from '@/Components/UI/Dropdown.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import { computed } from 'vue';

const props = defineProps<{
    assessmentUuid: string;
    options: ReportOptions;
    /** An icon trigger, for the assessment lists' row actions (2.5.1). */
    iconOnly?: boolean;
}>();

/**
 * The panel's current state as query parameters — the same names
 * `ReportOptions::fromQuery()` reads, so one rename breaks loudly in a test
 * rather than quietly producing a default-configured file.
 */
const optionsQuery = computed(() => {
    const o = props.options;

    return new URLSearchParams({
        baseline: o.baseline,
        include_shelved: o.includeShelved ? '1' : '0',
        include_comments: o.includeComments ? '1' : '0',
        include_methodology: o.includeMethodology ? '1' : '0',
        show_assignees: o.showAssignees ? '1' : '0',
    }).toString();
});

interface ExportItem {
    key: string;
    label: string;
    hint: string;
    icon: IconName;
    routeName: string;
}

/**
 * Named by what the file *is*, not by what the button does. "Download
 * report" described a gesture and left the reader to guess the format;
 * "Word document (.docx)" is the thing that ends up on their disk.
 */
const items: ExportItem[] = [
    {
        key: 'html',
        label: 'Web page',
        hint: 'One self-contained .html file. Opens anywhere, offline.',
        icon: 'file-text',
        routeName: 'assessments.report.export-html',
    },
    {
        key: 'docx',
        label: 'Word document',
        hint: 'The prose sections and the tables, editable in Word.',
        icon: 'file-text',
        routeName: 'assessments.report.export-word',
    },
    {
        key: 'xlsx',
        label: 'Excel workbook',
        hint: 'One sheet per section, headers frozen, figures as numbers.',
        icon: 'layout-grid',
        routeName: 'assessments.report.export-excel',
    },
    {
        key: 'csv',
        label: 'Spreadsheet data',
        hint: 'Every section as .csv — risks, attacks, defences and links.',
        icon: 'list',
        routeName: 'assessments.report.defences-csv',
    },
    {
        key: 'json',
        label: 'Data interchange',
        hint: 'The whole assessment as .json, for backup or another instance.',
        icon: 'copy',
        routeName: 'assessments.report.export-json',
    },
];

function href(routeName: string, forceFull = false): string {
    const base = route(routeName, props.assessmentUuid);

    return forceFull ? `${base}?download=1` : `${base}?${optionsQuery.value}`;
}

const linkClass =
    'text-text-primary hover:bg-surface-2 focus-visible:outline-focus-ring flex w-full items-start gap-2 px-3 py-1.5 text-left text-xs outline-none focus-visible:outline-2 focus-visible:-outline-offset-2';
</script>

<template>
    <Dropdown align="right" width="80">
        <template #trigger>
            <IconButton
                v-if="iconOnly"
                icon="download"
                label="Export"
                aria-haspopup="menu"
            />
            <Button v-else size="sm" icon="file-text" aria-haspopup="menu">
                Export
            </Button>
        </template>

        <template #content>
            <div role="menu" aria-label="Export formats">
                <p
                    class="text-text-muted px-3 pt-1 pb-1.5 text-[11px] leading-snug"
                >
                    As configured above.
                </p>

                <a
                    v-for="item in items"
                    :key="item.key"
                    role="menuitem"
                    :href="href(item.routeName)"
                    :class="linkClass"
                >
                    <Icon
                        :name="item.icon"
                        :size="14"
                        class="text-text-muted mt-0.5 shrink-0"
                    />
                    <span class="min-w-0">
                        <span class="block font-medium">{{ item.label }}</span>
                        <span class="text-text-muted block leading-snug">{{
                            item.hint
                        }}</span>
                    </span>
                </a>

                <!--
                    The forced-full path W33 defined, kept as an item rather
                    than as a second button (D35). `download=1` overrides
                    every other parameter server-side, so this cannot be
                    partially undone by whatever the panel happens to be set
                    to — which was the entire point of having it.
                -->
                <div class="border-border-subtle mt-1 border-t pt-1">
                    <a
                        role="menuitem"
                        :href="href('assessments.report.export-html', true)"
                        :class="linkClass"
                    >
                        <Icon
                            name="square-plus"
                            :size="14"
                            class="text-text-muted mt-0.5 shrink-0"
                        />
                        <span class="min-w-0">
                            <span class="block font-medium"
                                >Everything expanded</span
                            >
                            <span class="text-text-muted block leading-snug"
                                >A web page with every section and both states,
                                whatever the options say. Assignees stay
                                off.</span
                            >
                        </span>
                    </a>
                </div>
            </div>
        </template>
    </Dropdown>
</template>
