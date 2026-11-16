/**
 * The report sections `omit_sections` can switch off, in
 * document order. Mirrors `ReportOptions::SECTIONS`; a test compares them.
 */
export interface ReportSectionInfo {
    id: string;
    label: string;
    group: 'body' | 'appendix';
    /** One line under the switch, saying what the section holds. */
    description: string;
}

export const REPORT_SECTIONS: ReportSectionInfo[] = [
    {
        id: 'cover',
        label: 'Cover',
        group: 'body',
        description:
            'Title, owner, date, currency and how many risk and attack pairs are valued.',
    },
    {
        id: 'written-summary',
        label: 'Written summary',
        group: 'body',
        description: 'The summary in your own words.',
    },
    {
        id: 'executive-summary',
        label: 'Executive summary',
        group: 'body',
        description:
            'Inherent, current and target annual loss, and what the defences buy.',
    },
    {
        id: 'programme',
        label: 'Programme',
        group: 'body',
        description:
            'What the planned defences cost against the risk they remove.',
    },
    {
        id: 'risk-register',
        label: 'Risk register',
        group: 'body',
        description:
            'Every risk, its impact, the attacks behind it and its loss before and after.',
    },
    {
        id: 'defences-importance',
        label: 'Defences ranked by cost benefit',
        group: 'body',
        description:
            'Implemented defences: the loss that would return without each, cost, CBR and ROSI.',
    },
    {
        id: 'defences-opportunity',
        label: 'Defences ranked by opportunity',
        group: 'body',
        description: 'Planned defences, by what each would buy. The roadmap.',
    },
    {
        id: 'transfers',
        label: 'Transfers',
        group: 'body',
        description: 'What is transferred and what loss is retained.',
    },
    {
        id: 'control-coverage',
        label: 'Control coverage',
        group: 'body',
        description:
            'Framework controls the defences cite, and whether each is implemented, planned or shelved.',
    },
    {
        id: 'tree',
        label: 'Full assessment',
        group: 'body',
        description:
            'The whole assessment, with every value entered and computed.',
    },
    {
        id: 'references',
        label: 'References',
        group: 'appendix',
        description: 'The sources the assessment cites.',
    },
];

/** The heading a left-out section is named by, as the exports print it. */
export function omittedLabel(id: string): string {
    const label = REPORT_SECTIONS.find((s) => s.id === id)?.label ?? id;

    return id === 'references' ? `Appendix: ${label.toLowerCase()}` : label;
}
