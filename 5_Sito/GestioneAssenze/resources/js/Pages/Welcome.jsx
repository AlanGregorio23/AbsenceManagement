import { Head, Link } from '@inertiajs/react';

export default function Welcome({ auth }) {
    return (
        <>
            <Head title="Home" />
            <div className="min-h-screen bg-slate-100 text-slate-900">
                <div className="mx-auto flex min-h-screen max-w-4xl flex-col px-6">
                    <header className="flex items-center justify-between py-8">
                        <div>
                            <p className="text-xs uppercase tracking-widest text-slate-400">
                                SAMT Informatica
                            </p>
                            <h1 className="text-2xl font-semibold text-slate-900">
                                SAMT Informatica
                            </h1>
                        </div>
                        <nav className="flex items-center gap-3">
                            {auth.user ? (
                                <Link
                                    href={route('dashboard')}
                                    className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                                >
                                    Vai alla dashboard
                                </Link>
                            ) : (
                                <Link
                                    href={route('login')}
                                    className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-white"
                                >
                                    Accedi
                                </Link>
                            )}
                        </nav>
                    </header>

                    <main className="flex flex-1 items-center">
                        <div className="w-full rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                            <h2 className="text-2xl font-semibold text-slate-900">
                                Benvenuto nel portale assenze
                            </h2>
                            <p className="mt-3 text-sm text-slate-600">
                                Qui puoi gestire assenze, ritardi e documenti in modo
                                semplice e veloce.
                            </p>
                            {!auth.user && (
                                <div className="mt-6 flex flex-wrap gap-3">
                                    <Link
                                        href={route('login')}
                                        className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                                    >
                                        Accedi
                                    </Link>
                                </div>
                            )}
                        </div>
                    </main>

                    <footer className="py-6 text-center text-xs text-slate-400">
                        SAMT Informatica
                    </footer>
                </div>
            </div>
        </>
    );
}
