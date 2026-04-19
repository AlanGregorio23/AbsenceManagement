import ApplicationLogo from '@/Components/ApplicationLogo';

export default function GuestLayout({ children }) {
    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-100 px-4 py-8 sm:px-6">
            <div className="pointer-events-none absolute -left-20 -top-16 h-56 w-56 rounded-full bg-sky-200/40 blur-3xl" />
            <div className="pointer-events-none absolute -bottom-20 -right-16 h-64 w-64 rounded-full bg-indigo-200/35 blur-3xl" />

            <div className="w-full max-w-[27rem] rounded-3xl border border-slate-200/80 bg-white/95 p-5 shadow-2xl backdrop-blur sm:p-7">
                <div className="mb-5 flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/90 px-3.5 py-3">
                    <ApplicationLogo className="h-10 w-auto max-w-[6.5rem] object-contain" />
                    <div className="min-w-0">
                        <p className="truncate text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                            Gestione Assenze
                        </p>
                        <p className="truncate text-sm font-semibold text-slate-900">
                            SAMT Informatica
                        </p>
                    </div>
                </div>
                <div className="pb-1">
                    {children}
                </div>
            </div>
        </div>
    );
}
