import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import NotificationPreferencesForm from './Partials/NotificationPreferencesForm';
import StudentStatusSettingsForm from './Partials/StudentStatusSettingsForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
    notificationPreferences = [],
    studentStatusSettings = null,
}) {
    return (
        <AuthenticatedLayout
            header="Impostazioni account"
        >
            <Head title="Impostazioni account" />

            <div className="py-10">
                <div className="w-full max-w-none space-y-6">
                    <div id="profilo" className="scroll-mt-24 bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div id="impostazioni" className="scroll-mt-24 bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <NotificationPreferencesForm
                            preferences={notificationPreferences}
                            status={status}
                            className="max-w-3xl"
                        />
                    </div>

                    {studentStatusSettings && (
                        <div id="stato-allievi" className="scroll-mt-24 bg-white p-4 shadow sm:rounded-lg sm:p-8">
                            <StudentStatusSettingsForm
                                settings={studentStatusSettings}
                                status={status}
                                className="max-w-3xl"
                            />
                        </div>
                    )}

                    <div id="sicurezza" className="scroll-mt-24 bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
