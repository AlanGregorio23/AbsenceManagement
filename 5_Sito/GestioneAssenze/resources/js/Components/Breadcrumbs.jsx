import { Link } from '@inertiajs/react';

export default function Breadcrumbs({ items = [], className = '' }) {
    const safeItems = Array.isArray(items)
        ? items.filter((item) => String(item?.label ?? '').trim() !== '')
        : [];

    if (safeItems.length <= 1) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb" className={className}>
            <ol className="flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
                {safeItems.map((item, index) => {
                    const isLast = index === safeItems.length - 1;

                    return (
                        <li key={`${item.label}-${index}`} className="flex items-center gap-1.5">
                            {index > 0 && <span className="select-none text-slate-300">{'>'}</span>}
                            {item.href ? (
                                <Link
                                    href={item.href}
                                    className={isLast
                                        ? 'font-semibold text-slate-800 hover:underline'
                                        : 'text-slate-600 hover:text-slate-800 hover:underline'}
                                >
                                    {item.label}
                                </Link>
                            ) : (
                                <span className={isLast ? 'font-semibold text-slate-800' : 'text-slate-600'}>
                                    {item.label}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
