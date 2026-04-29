const normalizeHoursValue = (value) => {
    const parsed = Number(value);

    if (!Number.isFinite(parsed)) {
        return 0;
    }

    return Math.max(Math.trunc(parsed), 0);
};

const hourUnit = (hours) => (hours === 1 ? 'ora' : 'ore');

export const buildAnnualHoursLimitLabels = (value) => {
    const hours = normalizeHoursValue(value);
    const limit =
        hours > 0
            ? `limite annuale di ${hours} ${hourUnit(hours)}`
            : 'limite ore annuale';

    return {
        value: hours,
        limit,
        included: `Rientra nel ${limit}`,
        excluded: `Esclusa dal ${limit}`,
        excludedMasculine: `Escluso dal ${limit}`,
        counted: `Conteggio ${limit}`,
        countedNote: `Nota conteggio ${limit}`,
        ruleReasonComment: `Esclusa dal ${limit} per regola configurata sul motivo.`,
    };
};

export const resolveAnnualHoursLimitLabels = (pageProps = {}) => {
    const raw = pageProps?.annualHoursLimitLabels ?? {};
    const fallback = buildAnnualHoursLimitLabels(raw.value);

    return {
        value: fallback.value,
        limit: String(raw.limit ?? '').trim() || fallback.limit,
        included: String(raw.included ?? '').trim() || fallback.included,
        excluded: String(raw.excluded ?? '').trim() || fallback.excluded,
        excludedMasculine:
            String(raw.excludedMasculine ?? '').trim() || fallback.excludedMasculine,
        counted: String(raw.counted ?? '').trim() || fallback.counted,
        countedNote: String(raw.countedNote ?? '').trim() || fallback.countedNote,
        ruleReasonComment:
            String(raw.ruleReasonComment ?? '').trim() || fallback.ruleReasonComment,
    };
};

export const hoursOnLimitLabel = (value) => {
    const hours = normalizeHoursValue(value);

    return hours > 0 ? `Ore su ${hours}` : 'Ore sul limite annuale';
};

export const onLimitShortLabel = (value) => {
    const hours = normalizeHoursValue(value);

    return hours > 0 ? `Su ${hours}` : 'Sul limite';
};
