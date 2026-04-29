const iconPaths = {
    requests: 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h5',
    signature: 'M12 4a8 8 0 1 1 0 16 8 8 0 0 1 0-16zM12 8v5l3 2',
    warning: 'M12 3 2 21h20L12 3zM12 9v5M12 17h.01',
    docs: 'M6 3h8l4 4v14H6zM14 3v5h5M9 13h6M9 17h6',
    users: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
    classes: 'M3 8l9-4 9 4-9 4zM6 10v5c0 1.8 2.7 3.2 6 3.2s6-1.4 6-3.2v-5',
    teacher: 'M12 4 3 8l9 4 9-4zM6 10v5c0 2 3 4 6 4s6-2 6-4v-5',
    errors: 'M12 3 2 21h20L12 3zM12 9v5M12 17h.01',
    calendar: 'M4 6h16v14H4zM8 3v5M16 3v5M4 10h16',
    clock: 'M12 4a8 8 0 1 1 0 16 8 8 0 0 1 0-16zM12 8v5l3 2',
    check: 'M20 6 9 17l-5-5',
    chart: 'M5 19V5M9 19v-6M13 19V9M17 19v-9M21 19H3',
};

const toneClasses = {
    sky: 'bg-sky-100 text-sky-700',
    amber: 'bg-amber-100 text-amber-700',
    emerald: 'bg-emerald-100 text-emerald-700',
    rose: 'bg-rose-100 text-rose-700',
    violet: 'bg-violet-100 text-violet-700',
    indigo: 'bg-indigo-100 text-indigo-700',
};

export default function DashboardStatCard({
    label,
    value,
    helper = '',
    icon = 'chart',
    tone = 'sky',
}) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md">
            <div className="flex items-start justify-between gap-4">
                <p className="text-sm text-slate-500">{label}</p>
                <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ${toneClasses[tone] ?? toneClasses.sky}`}>
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.8"
                        className="h-5 w-5"
                    >
                        <path d={iconPaths[icon] ?? iconPaths.chart} />
                    </svg>
                </span>
            </div>
            <div className="mt-3 flex items-end justify-between gap-3">
                <span className="shrink-0 text-2xl font-semibold text-slate-900">
                    {value}
                </span>
                {helper !== '' && (
                    <span className="min-w-0 rounded-full bg-slate-100 px-3 py-1 text-center text-xs leading-5 text-slate-500">
                        {helper}
                    </span>
                )}
            </div>
        </div>
    );
}
