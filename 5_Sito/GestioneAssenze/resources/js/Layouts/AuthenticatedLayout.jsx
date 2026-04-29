import ApplicationLogo from '@/Components/ApplicationLogo';
import Breadcrumbs from '@/Components/Breadcrumbs';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const Svg = ({ d, c = 'h-5 w-5' }) => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className={c}>
        <path d={d} />
    </svg>
);

const ICON = {
    dashboard: 'M4 4h7v7H4zM13 4h7v5h-7zM13 11h7v9h-7zM4 13h7v7H4z',
    rules: 'M7 4h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM9 8h6M9 12h6M9 16h4',
    absence: 'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM8 3v4M16 3v4M3 10h18',
    delay: 'M12 4a8 8 0 1 1 0 16 8 8 0 0 1 0-16zM12 8v5l3 2',
    leave: 'M4 7h16M6 4h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9 12h6',
    history: 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 8v4l3 2',
    docs: 'M4 6h16v12H4zM7 3h10v3H7',
    report: 'M5 4h14v16H5zM9 16v-3M12 16V9M15 16v-6',
    users: 'M16 11a4 4 0 1 0-8 0M3 20a6 6 0 0 1 18 0',
    classes: 'M3 8l9-4 9 4-9 4zM6 10v5c0 1.8 2.7 3.2 6 3.2s6-1.4 6-3.2v-5',
    settings: 'M4 6h16M4 12h16M4 18h16M8 6a1.5 1.5 0 1 0 0 .01M14 12a1.5 1.5 0 1 0 0 .01M10 18a1.5 1.5 0 1 0 0 .01',
    logs: 'M12 3 2 21h20L12 3zM12 9v5M12 17h.01',
    interactions: 'M8 7h13v10H8zM3 11h5M3 7h3M3 15h3',
    bell: 'M6 9a6 6 0 1 1 12 0c0 5 2 5 2 6H4c0-1 2-1 2-6M10 18a2 2 0 0 0 4 0',
    search: 'M11 18a7 7 0 1 1 5-2l4 4',
    chevronDown: 'M6 9l6 6 6-6',
    chevronRight: 'M9 6l6 6-6 6',
    profile: 'M20 21a8 8 0 0 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
    logout: 'M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M16 12H9M13 9l3 3-3 3',
    menu: 'M4 6h16M4 12h16M4 18h16',
};

const ROLE = {
    student: 'studente',
    teacher: 'docente',
    laboratory_manager: 'capo laboratorio',
    admin: 'amministratore',
    guardian: 'tutore',
};
const normalize = (value) => String(value ?? '').trim().toLowerCase();
const routeSafe = (name, params = {}) => {
    try {
        return route(name, params);
    } catch {
        return null;
    }
};

const buildBreadcrumbItems = (component, role, sourceRoute = '', pageProps = {}) => {
    if (
        component === 'Dashboard/Admin'
        || component === 'Dashboard/Teacher'
        || component === 'Dashboard/Student'
        || component === 'Dashboard/LaboratoryManager'
    ) {
        return [{ label: 'Dashboard' }];
    }

    const simplePages = {
        'Shared/Rules': 'Regole',
        'Profile/Edit': 'Profilo',
        'Student/AbsenceCreate': 'Segnala assenza',
        'Student/DelayCreate': 'Segnala ritardo',
        'Student/LeaveCreate': 'Richiesta congedo',
        'Student/History': 'Storico',
        'Student/Documents': 'Documenti',
        'Student/MonthlyReports': 'Report mensili',
        'Teacher/Classes': 'Le mie classi',
        'Teacher/Students': 'Studenti',
        'Teacher/History': 'Storico',
        'Teacher/MonthlyReports': 'Report mensili',
        'LaboratoryManager/Leaves': 'Richieste congedo',
        'LaboratoryManager/LeaveCreate': 'Nuovo congedo',
        'LaboratoryManager/Students': 'Allievi',
        'LaboratoryManager/History': 'Storico congedi',
        'Admin/Users': 'Gestione utenti',
        'Admin/Classes': 'Gestione classi',
        'Admin/Settings': 'Configurazione',
        'Admin/ErrorLogs': 'Log errori',
        'Admin/Interactions': 'Storico interazioni',
    };

    if (simplePages[component]) {
        return [{ label: simplePages[component] }];
    }

    if (component === 'Teacher/AbsenceDetail') {
        return [
            { label: 'Dashboard', href: routeSafe('dashboard') },
            { label: 'Dettaglio assenza' },
        ];
    }

    if (component === 'Teacher/DelayDetail') {
        return [
            { label: 'Dashboard', href: routeSafe('dashboard') },
            { label: 'Dettaglio ritardo' },
        ];
    }

    if (component === 'Teacher/MonthlyReportDetail') {
        return [
            { label: 'Report mensili', href: routeSafe('teacher.monthly-reports') },
            { label: 'Dettaglio report' },
        ];
    }

    if (component === 'Student/AbsenceDraftFromLeave') {
        return [
            { label: 'Storico', href: routeSafe('student.history') },
            { label: 'Bozza assenza da congedo' },
        ];
    }

    if (component === 'Shared/StudentProfile') {
        if (role === 'teacher') {
            return [
                { label: 'Studenti', href: routeSafe('teacher.students') },
                { label: 'Profilo studente' },
            ];
        }

        if (role === 'laboratory_manager') {
            return [
                { label: 'Allievi', href: routeSafe('lab.students') },
                { label: 'Profilo studente' },
            ];
        }

        return [{ label: 'Profilo studente' }];
    }

    if (component === 'Admin/AddUser') {
        return [
            { label: 'Gestione utenti', href: routeSafe('admin.users') },
            { label: 'Aggiungi utente' },
        ];
    }

    if (component === 'Admin/LogsExport') {
        if (sourceRoute === 'admin.interactions') {
            return [
                { label: 'Storico interazioni', href: routeSafe('admin.interactions') },
                { label: 'Esporta log' },
            ];
        }

        return [
            { label: 'Log errori', href: routeSafe('admin.error-logs') },
            { label: 'Esporta log' },
        ];
    }

    if (component === 'LaboratoryManager/LeaveDetail') {
        if (role === 'teacher') {
            const dashboardUrl = routeSafe('dashboard');
            const absenceDetailUrl = String(pageProps?.item?.registered_absence_url ?? '').trim();

            return [
                { label: 'Dashboard', href: dashboardUrl },
                ...(absenceDetailUrl !== ''
                    ? [{ label: 'Dettaglio assenza', href: absenceDetailUrl }]
                    : []),
                { label: 'Congedo' },
            ];
        }

        return [
            { label: 'Dashboard', href: routeSafe('dashboard') },
            { label: 'Dettaglio congedo' },
        ];
    }

    return [{ label: 'Dashboard', href: routeSafe('dashboard') }];
};

export default function AuthenticatedLayout({ children, showBreadcrumbs = true }) {
    const page = usePage();
    const { auth, notifications, global_search: globalSearchIndex = [] } = page.props;
    const user = auth?.user;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [notifOpen, setNotifOpen] = useState(false);
    const [profileOpen, setProfileOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [searchOpen, setSearchOpen] = useState(false);

    const notifRef = useRef(null);
    const profileRef = useRef(null);
    const searchRef = useRef(null);

    const notificationItems = Array.isArray(notifications?.items) ? notifications.items : [];
    const unreadCount = Number(notifications?.unread_count ?? 0);

    const sections = useMemo(() => {
        const menu = [
            { l: 'Dashboard', i: 'dashboard', h: route('dashboard'), a: ['dashboard'], k: ['home', 'principale', 'panoramica'] },
        ];
        const rulesSection = {
            title: 'Regole',
            items: [
            { l: 'Regole', i: 'rules', h: route('rules.index'), a: ['rules.*'], k: ['norme', 'regolamento', 'criteri'] },
            ],
        };

        if (user?.role === 'student') {
            return [
                { title: 'Menu', items: menu },
                {
                    title: 'Richieste',
                    items: [
                        { l: 'Segnala assenza', i: 'absence', h: route('student.absences.create'), a: ['student.absences.*'], k: ['assenze', 'richiesta assenza', 'nuova assenza', 'ore richieste'] },
                        { l: 'Segnala ritardo', i: 'delay', h: route('student.delays.create'), a: ['student.delays.*'], k: ['ritardi', 'nuovo ritardo', 'ritardo'] },
                        { l: 'Richiesta congedo', i: 'leave', h: route('student.leaves.create'), a: ['student.leaves.*'], k: ['congedi', 'permesso', 'richiesta', 'periodi', 'dal al'] },
                    ],
                },
                {
                    title: 'Archivio',
                    items: [
                        { l: 'Storico', i: 'history', h: route('student.history'), a: ['student.history'], k: ['storico richieste', 'cronologia', 'elementi storico', 'richieste passate'] },
                        { l: 'Documenti', i: 'docs', h: route('student.documents'), a: ['student.documents'], k: ['file', 'allegati', 'certificati', 'documenti interni', 'documentazione'] },
                        { l: 'Report mensili', i: 'report', h: route('student.monthly-reports'), a: ['student.monthly-reports.*'], k: ['report', 'mensili', 'pdf', 'resoconto', 'remprot'] },
                    ],
                },
                rulesSection,
            ];
        }

        if (user?.role === 'teacher') {
            return [
                { title: 'Menu', items: menu },
                {
                    title: 'Classi',
                    items: [
                        { l: 'Le mie classi', i: 'classes', h: route('teacher.classes'), a: ['teacher.classes'], k: ['classi', 'mie classi'] },
                        { l: 'Studenti', i: 'users', h: route('teacher.students'), a: ['teacher.students', 'students.profile.*'], k: ['allievi', 'profili studenti'] },
                    ],
                },
                {
                    title: 'Richieste',
                    items: [
                        { l: 'Storico', i: 'history', h: route('teacher.history'), a: ['teacher.history'], k: ['cronologia', 'elementi storico', 'richieste passate'] },
                        { l: 'Report mensili', i: 'report', h: route('teacher.monthly-reports'), a: ['teacher.monthly-reports.*'], k: ['report', 'mensili', 'approvazioni', 'resoconti'] },
                    ],
                },
                rulesSection,
            ];
        }

        if (user?.role === 'laboratory_manager') {
            return [
                { title: 'Menu', items: menu },
                {
                    title: 'Gestione',
                    items: [
                        { l: 'Nuovo congedo', i: 'leave', h: route('lab.leaves.create'), a: ['lab.leaves.create', 'lab.leaves.store'], k: ['crea congedo', 'inserimento', 'nuova richiesta'] },
                        { l: 'Allievi', i: 'users', h: route('lab.students'), a: ['lab.students'], k: ['studenti'] },
                        { l: 'Storico', i: 'history', h: route('lab.history'), a: ['lab.history'], k: ['cronologia'] },
                    ],
                },
                rulesSection,
            ];
        }

        return [
            { title: 'Menu', items: menu },
            {
                title: 'Gestione',
                items: [
                    { l: 'Gestione utenti', i: 'users', h: route('admin.users'), a: ['admin.users.*'], k: ['utenti', 'allievi', 'docenti'] },
                    { l: 'Gestione classi', i: 'classes', h: route('admin.classes'), a: ['admin.classes.*'], k: ['classi'] },
                    { l: 'Configurazione', i: 'settings', h: route('admin.settings'), a: ['admin.settings.*'], k: ['impostazioni', 'sistema'] },
                ],
            },
            {
                title: 'Controlli',
                items: [
                    { l: 'Log errori', i: 'logs', h: route('admin.error-logs'), a: ['admin.error-logs.*'], k: ['errori', 'warning'] },
                    { l: 'Storico interazioni', i: 'interactions', h: route('admin.interactions'), a: ['admin.interactions.*'], k: ['interazioni', 'audit'] },
                ],
            },
            rulesSection,
        ];
    }, [user?.role]);

    const allMenuItems = useMemo(
        () =>
            sections.flatMap((section) =>
                section.items.map((item) => ({
                    ...item,
                    sectionTitle: section.title,
                }))
            ),
        [sections]
    );
    const isActive = (item) => item.a.some((name) => route().current(name));

    const searchResults = useMemo(() => {
        const query = normalize(search);
        if (query === '') return [];

        const menuMatches = allMenuItems
            .filter((item) =>
                normalize(`${item.l} ${item.sectionTitle} ${(item.k ?? []).join(' ')}`).includes(query)
            )
            .slice(0, 8)
            .map((item) => ({
                key: `menu-${item.l}-${item.h}`,
                type: 'menu',
                label: item.l,
                subtitle: 'Pagina',
                icon: item.i,
                payload: item,
            }));

        const requestMatches = (Array.isArray(globalSearchIndex) ? globalSearchIndex : [])
            .filter((item) =>
                normalize(`${item.label} ${item.subtitle} ${item.tokens ?? ''}`).includes(query)
            )
            .slice(0, 8)
            .map((item) => ({
                key: `request-${item.key}`,
                type: 'request',
                label: item.label,
                subtitle: item.subtitle || 'Richiesta',
                icon: item.icon || 'search',
                payload: item,
            }));

        const notificationMatches = notificationItems
            .filter((item) => normalize(`${item.title} ${item.body}`).includes(query))
            .slice(0, 4)
            .map((item) => ({
                key: `notification-${item.id}`,
                type: 'notification',
                label: item.title,
                subtitle: item.body,
                icon: 'bell',
                payload: item,
            }));

        return [...requestMatches, ...menuMatches, ...notificationMatches].slice(0, 12);
    }, [allMenuItems, globalSearchIndex, notificationItems, search]);

    useEffect(() => {
        const closeOutside = (event) => {
            if (notifRef.current && !notifRef.current.contains(event.target)) setNotifOpen(false);
            if (profileRef.current && !profileRef.current.contains(event.target)) setProfileOpen(false);
            if (searchRef.current && !searchRef.current.contains(event.target)) setSearchOpen(false);
        };
        document.addEventListener('mousedown', closeOutside);
        return () => document.removeEventListener('mousedown', closeOutside);
    }, []);

    const openNotification = (item) => {
        const navigate = () => {
            setNotifOpen(false);
            if (!item?.url) return;
            if (String(item.action_type ?? 'open').toLowerCase() === 'download') {
                window.open(item.url, '_blank', 'noopener,noreferrer');
                return;
            }
            router.visit(item.url);
        };

        if (!item?.is_read) {
            router.post(route('notifications.read', item.id), {}, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: navigate,
            });
            return;
        }

        navigate();
    };

    const executeSearchResult = (result) => {
        setSearchOpen(false);
        if (result.type === 'notification') {
            openNotification(result.payload);
            return;
        }
        if (result.type === 'request') {
            router.visit(result.payload.url);
            return;
        }
        router.visit(result.payload.h);
    };

    const submitSearch = (event) => {
        event.preventDefault();
        const query = search.trim();
        if (query === '') return;

        if (searchResults.length > 0) {
            executeSearchResult(searchResults[0]);
        }
    };

    const profileInitial = String(user?.name ?? 'U').trim().charAt(0).toUpperCase() || 'U';
    const breadcrumbs = useMemo(
        () =>
            buildBreadcrumbItems(
                page.component,
                user?.role,
                String(page.props?.sourceRoute ?? ''),
                page.props
            ),
        [page.component, page.props, user?.role]
    );
    const showResolvedBreadcrumbs = showBreadcrumbs && breadcrumbs.length > 1;

    return (
        <div className="min-h-screen bg-slate-100 text-slate-900">
            <div className="relative flex min-h-screen">
                <aside
                    className={`fixed inset-y-0 left-0 z-30 flex w-60 transform flex-col border-r border-slate-200 bg-white px-3 py-4 transition lg:translate-x-0 ${
                        sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                    }`}
                >
                    <div className="flex items-center gap-2 px-1">
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

                    <nav className="mt-5 flex-1 overflow-y-auto border-t border-slate-100 pt-3 pr-1">
                        {sections.map((section, index) => (
                            <section
                                key={section.title}
                                className={index === 0 ? '' : 'mt-3 border-t border-slate-100 pt-3'}
                            >
                                <p className="px-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    {section.title}
                                </p>
                                <div className="mt-1 space-y-1">
                                    {section.items.map((item) => (
                                        <Link
                                            key={`${section.title}-${item.l}`}
                                            href={item.h}
                                            className={`group flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                                                isActive(item)
                                                    ? 'bg-indigo-50 text-indigo-800 shadow-sm hover:bg-indigo-100'
                                                    : 'text-slate-600 hover:bg-sky-50 hover:text-sky-800'
                                            }`}
                                        >
                                            <span className={isActive(item) ? 'text-indigo-700' : 'text-slate-400 group-hover:text-sky-700'}>
                                                <Svg d={ICON[item.i]} />
                                            </span>
                                            <span className="truncate">{item.l}</span>
                                        </Link>
                                    ))}
                                </div>
                            </section>
                        ))}
                    </nav>
                </aside>

                {sidebarOpen && (
                    <button
                        type="button"
                        onClick={() => setSidebarOpen(false)}
                        className="fixed inset-0 z-20 bg-slate-900/40 lg:hidden"
                        aria-label="Chiudi menu"
                    />
                )}

                <div className="flex min-h-screen min-w-0 flex-1 flex-col lg:ml-60">
                    <header className="fixed left-0 right-0 top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur lg:left-60">
                        <div className="px-4 py-3 sm:px-6">
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                <button
                                    type="button"
                                    onClick={() => setSidebarOpen(true)}
                                    className="rounded-lg border border-slate-200 p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
                                >
                                    <Svg d={ICON.menu} c="h-5 w-5" />
                                </button>

                                <div className="relative min-w-0 flex-1 sm:max-w-3xl" ref={searchRef}>
                                    <form onSubmit={submitSearch} className="relative">
                                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                                            <Svg d={ICON.search} c="h-4 w-4" />
                                        </span>
                                        <input
                                            value={search}
                                            onChange={(event) => {
                                                setSearch(event.target.value);
                                                setSearchOpen(true);
                                            }}
                                            onFocus={() => setSearchOpen(true)}
                                            placeholder="Cerca in pagine, richieste, storico, documenti, report, ritardi, regole..."
                                            className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm outline-none transition focus:border-slate-300 focus:bg-white"
                                        />
                                    </form>

                                    {searchOpen && search.trim() !== '' && (
                                        <div className="absolute mt-2 w-full rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
                                            {searchResults.length === 0 && (
                                                <p className="px-2 py-2 text-xs text-slate-500">
                                                    Nessun risultato diretto. Premi invio per cercare nel contenuto della pagina.
                                                </p>
                                            )}

                                            {searchResults.map((result) => (
                                                <button
                                                    key={result.key}
                                                    type="button"
                                                    onClick={() => executeSearchResult(result)}
                                                    className="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left text-sm text-slate-700 hover:bg-slate-100"
                                                >
                                                    <span className="flex min-w-0 items-center gap-2">
                                                        <Svg d={ICON[result.icon]} c="h-4 w-4 text-slate-500" />
                                                        <span className="truncate">
                                                            <span className="block truncate font-medium text-slate-800">{result.label}</span>
                                                            <span className="block truncate text-xs text-slate-500">{result.subtitle}</span>
                                                        </span>
                                                    </span>
                                                    <Svg d={ICON.chevronRight} c="h-4 w-4 shrink-0 text-slate-400" />
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>

                                <div className="flex items-center gap-2">
                                    <div className="relative" ref={notifRef}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setNotifOpen((current) => !current);
                                            setProfileOpen(false);
                                        }}
                                        className="relative rounded-full border border-slate-200 p-2 text-slate-500 hover:bg-slate-100"
                                    >
                                        <Svg d={ICON.bell} />
                                    </button>

                                    {unreadCount > 0 && (
                                        <span className="absolute -right-1 -top-1 rounded-full bg-rose-500 px-1 text-[10px] font-semibold text-white">
                                            {unreadCount > 99 ? '99+' : unreadCount}
                                        </span>
                                    )}
                                    {notifOpen && (
                                        <div className="fixed right-2 top-[72px] z-40 w-[min(22rem,calc(100vw-1rem))] rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl sm:absolute sm:right-0 sm:top-auto sm:mt-3 sm:w-[23rem]">
                                            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                                <div>
                                                    <p className="text-base font-semibold text-slate-900">
                                                        Notifiche
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {unreadCount > 0
                                                            ? `${unreadCount} non lette`
                                                            : 'Nessuna notifica non letta'}
                                                    </p>
                                                </div>
                                                {unreadCount > 0 && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                route('notifications.read-all'),
                                                                {},
                                                                { preserveScroll: true, preserveState: true }
                                                            )
                                                        }
                                                        className="text-xs font-semibold text-sky-700 hover:text-sky-800"
                                                    >
                                                        Segna tutte
                                                    </button>
                                                )}
                                            </div>

                                            <div className="mt-3 max-h-[60vh] space-y-2 overflow-y-auto pr-1 sm:max-h-[22rem]">
                                                {notificationItems.length === 0 && (
                                                    <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                                                        Nessuna notifica disponibile.
                                                    </div>
                                                )}
                                                {notificationItems.map((item) => (
                                                    <button
                                                        key={item.id}
                                                        type="button"
                                                        onClick={() => openNotification(item)}
                                                        className={`w-full rounded-xl border p-3 text-left transition ${
                                                            item.is_read
                                                                ? 'border-slate-200 bg-white hover:border-slate-300'
                                                                : 'border-sky-200 bg-sky-50/70 hover:border-sky-300'
                                                        }`}
                                                    >
                                                        <p className="text-sm font-semibold text-slate-900">
                                                            {item.title}
                                                        </p>
                                                        <p className="mt-1 text-sm text-slate-600">
                                                            {item.body}
                                                        </p>
                                                        <p className="mt-2 text-xs text-slate-500">
                                                            {item.created_at}
                                                        </p>
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    </div>

                                    <div className="relative" ref={profileRef}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setProfileOpen((current) => !current);
                                            setNotifOpen(false);
                                        }}
                                        className="flex items-center gap-2 rounded-full px-1.5 py-1 pr-2 hover:bg-slate-100"
                                    >
                                        <span className="flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-slate-200 text-sm font-semibold text-slate-700">
                                            {user?.avatar_url ? (
                                                <img
                                                    src={user.avatar_url}
                                                    alt={user?.name ?? 'Avatar'}
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                profileInitial
                                            )}
                                        </span>
                                        <span className="hidden text-left sm:block">
                                            <span className="block text-sm font-semibold leading-tight text-slate-900">
                                                {user?.name}
                                            </span>
                                            <span className="block text-xs leading-tight text-slate-500">
                                                {ROLE[user?.role] ?? 'utente'}
                                            </span>
                                        </span>
                                        <Svg d={ICON.chevronDown} c={`h-4 w-4 text-slate-500 transition ${profileOpen ? 'rotate-180' : ''}`} />
                                    </button>

                                    {profileOpen && (
                                        <div className="absolute right-0 z-40 mt-3 w-80 rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl">
                                            <div className="rounded-xl bg-slate-50 px-3 py-3">
                                                <p className="truncate text-sm font-semibold text-slate-900">
                                                    {user?.name}
                                                </p>
                                                <p className="mt-0.5 truncate text-xs text-slate-500">
                                                    {user?.email ?? '-'}
                                                </p>
                                            </div>

                                            <div className="mt-2">
                                                <Link
                                                    href={`${route('profile.edit')}#impostazioni`}
                                                    className="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-100"
                                                >
                                                    <span className="flex items-center gap-3">
                                                        <Svg d={ICON.settings} c="h-5 w-5 text-slate-500" />
                                                        Impostazioni account
                                                    </span>
                                                    <Svg d={ICON.chevronRight} c="h-4 w-4 text-slate-400" />
                                                </Link>
                                            </div>

                                            <div className="my-2 border-t border-slate-200" />

                                            <Link
                                                href={route('logout')}
                                                method="post"
                                                as="button"
                                                className="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-700 hover:bg-slate-100"
                                            >
                                                <span className="flex items-center gap-3">
                                                    <Svg d={ICON.logout} c="h-5 w-5 text-slate-500" />
                                                    Logout
                                                </span>
                                                <Svg d={ICON.chevronRight} c="h-4 w-4 text-slate-400" />
                                            </Link>
                                        </div>
                                    )}
                                </div>
                                </div>
                            </div>

                        </div>
                    </header>

                    <main className="min-w-0 flex-1 px-4 pb-5 pt-[88px] sm:px-6">
                        {showResolvedBreadcrumbs && (
                            <div className="mb-2 flex justify-end">
                                <Breadcrumbs items={breadcrumbs} />
                            </div>
                        )}
                        {children}
                    </main>
                </div>
            </div>
        </div>
    );
}
