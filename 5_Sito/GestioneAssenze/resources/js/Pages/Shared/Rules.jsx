import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Rules({ sections = [], downloadUrl = '' }) {
    return (
        <AuthenticatedLayout header="Regole">
            <Head title="Regole sistema" />

            <div className="space-y-6">
                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">
                                Riassunto regole configurate
                            </h2>
                            <p className="text-sm text-slate-500">
                                Vista sola lettura delle regole attive nel database.
                            </p>
                        </div>
                        {downloadUrl !== '' && (
                            <a
                                href={downloadUrl}
                                className="btn-soft-neutral px-4 text-sm"
                            >
                                Scarica PDF
                            </a>
                        )}
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    {sections.map((section) => (
                        <article
                            key={section.title}
                            className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <h3 className="text-base font-semibold text-slate-900">
                                {section.title}
                            </h3>
                            <div className="mt-4 space-y-3">
                                {(section.items ?? []).map((item, index) => {
                                    const details = Array.isArray(item.details)
                                        ? item.details.filter(
                                              (line) =>
                                                  typeof line === 'string' &&
                                                  line.trim() !== ''
                                          )
                                        : [];

                                    return (
                                        <div
                                            key={`${section.title}-${index}`}
                                            className="rounded-xl border border-slate-200 bg-slate-50 p-3"
                                        >
                                            <p className="text-sm font-semibold text-slate-800">
                                                {item.label}
                                            </p>
                                            <p className="mt-1 text-sm text-slate-700">
                                                {item.value}
                                            </p>
                                            {details.length > 0 && (
                                                <ul className="mt-2 space-y-1 text-sm text-slate-600">
                                                    {details.map((line) => (
                                                        <li key={`${section.title}-${index}-${line}`}>
                                                            - {line}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </article>
                    ))}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
