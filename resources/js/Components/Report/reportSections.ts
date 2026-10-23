/**
 * The report sections `omit_sections` can switch off (W139, D118), in
 * document order. Mirrors `ReportOptions::SECTIONS`; a test compares them.
 */
export interface ReportSectionInfo {
    id: string;
    label: string;
    group: 'body' | 'appendix';
}

export const REPORT_SECTIONS: ReportSectionInfo[] = [
    { id: 'cover', label: 'Cover', group: 'body' },
    { id: 'written-summary', label: 'Written summary', group: 'body' },
    { id: 'executive-summary', label: 'Executive summary', group: 'body' },
    { id: 'programme', label: 'Programme', group: 'body' },
    { id: 'risk-register', label: 'Risk register', group: 'body' },
    {
        id: 'defences-importance',
        label: 'Defences ranked by cost benefit',
        group: 'body',
    },
    {
        id: 'defences-opportunity',
        label: 'Defences ranked by opportunity',
        group: 'body',
    },
    { id: 'transfers', label: 'Transfers', group: 'body' },
    { id: 'control-coverage', label: 'Control coverage', group: 'body' },
    { id: 'tree', label: 'Full assessment', group: 'body' },
    { id: 'references', label: 'References', group: 'appendix' },
];

/** The heading a left-out section is named by, as the exports print it. */
export function omittedLabel(id: string): string {
    const label = REPORT_SECTIONS.find((s) => s.id === id)?.label ?? id;

    return id === 'references' ? `Appendix: ${label.toLowerCase()}` : label;
}
