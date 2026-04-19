import PrimaryButton from '@/Components/PrimaryButton';
import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const buildPreferencesPayload = (preferences) =>
    preferences.reduce((accumulator, item) => {
        accumulator[item.key] = {
            web_enabled: Boolean(item.web_enabled),
            email_enabled: Boolean(item.email_enabled),
        };

        return accumulator;
    }, {});

function ChannelToggle({ checked, onChange, label }) {
    return (
        <label className="relative inline-flex cursor-pointer items-center">
            <input
                type="checkbox"
                checked={checked}
                onChange={onChange}
                className="peer sr-only"
                aria-label={label}
            />
            <span className="h-6 w-11 rounded-full bg-slate-300 transition-colors peer-checked:bg-blue-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-transform after:content-[''] peer-checked:after:translate-x-5" />
        </label>
    );
}

export default function NotificationPreferencesForm({
    preferences = [],
    status,
    className = '',
}) {
    const { data, setData, patch, processing, recentlySuccessful } = useForm({
        preferences: buildPreferencesPayload(preferences),
    });

    useEffect(() => {
        setData('preferences', buildPreferencesPayload(preferences));
    }, [preferences, setData]);

    const submit = (event) => {
        event.preventDefault();
        patch(route('profile.notifications.update'));
    };

    const updatePreference = (eventKey, channel, value) => {
        setData('preferences', {
            ...data.preferences,
            [eventKey]: {
                web_enabled: Boolean(data.preferences[eventKey]?.web_enabled),
                email_enabled: Boolean(data.preferences[eventKey]?.email_enabled),
                [channel]: value,
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Notifiche
                </h2>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-5">
                {preferences.length === 0 && (
                    <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        Nessuna preferenza notifiche disponibile per il tuo ruolo.
                    </div>
                )}

                {preferences.length > 0 && (
                    <>
                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Tipo notifica
                                        </th>
                                        <th className="w-28 px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Web
                                        </th>
                                        <th className="w-28 px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Email
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 bg-white">
                                    {preferences.map((item) => {
                                        const webEnabled = Boolean(
                                            data.preferences[item.key]?.web_enabled
                                        );
                                        const emailEnabled = Boolean(
                                            data.preferences[item.key]?.email_enabled
                                        );

                                        return (
                                            <tr key={item.key}>
                                                <td className="px-4 py-3">
                                                    <p className="text-sm font-semibold text-slate-800">
                                                        {item.label}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    <ChannelToggle
                                                        checked={webEnabled}
                                                        onChange={(event) =>
                                                            updatePreference(
                                                                item.key,
                                                                'web_enabled',
                                                                event.target.checked
                                                            )
                                                        }
                                                        label={`Web ${item.label}`}
                                                    />
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    <ChannelToggle
                                                        checked={emailEnabled}
                                                        onChange={(event) =>
                                                            updatePreference(
                                                                item.key,
                                                                'email_enabled',
                                                                event.target.checked
                                                            )
                                                        }
                                                        label={`Email ${item.label}`}
                                                    />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing || preferences.length === 0}>
                        Salva preferenze
                    </PrimaryButton>

                    <Transition
                        show={recentlySuccessful || status === 'notification-preferences-updated'}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">Preferenze salvate.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
