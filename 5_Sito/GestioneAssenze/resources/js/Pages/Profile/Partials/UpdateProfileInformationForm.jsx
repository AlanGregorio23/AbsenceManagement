import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}) {
    const user = usePage().props.auth.user;
    const fileInputRef = useRef(null);
    const localPreviewRef = useRef(null);
    const [avatarPreview, setAvatarPreview] = useState(user?.avatar_url ?? null);
    const [selectedFileName, setSelectedFileName] = useState('');

    const { data, setData, post, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
            avatar: null,
            remove_avatar: false,
            _method: 'patch',
        });

    useEffect(() => {
        if (!localPreviewRef.current) {
            setAvatarPreview(user?.avatar_url ?? null);
        }
    }, [user?.avatar_url]);

    useEffect(() => {
        return () => {
            if (localPreviewRef.current) {
                URL.revokeObjectURL(localPreviewRef.current);
            }
        };
    }, []);

    const onAvatarChange = (event) => {
        const file = event.target.files?.[0] ?? null;
        setData('avatar', file);
        setData('remove_avatar', false);
        setSelectedFileName(file?.name ?? '');

        if (localPreviewRef.current) {
            URL.revokeObjectURL(localPreviewRef.current);
            localPreviewRef.current = null;
        }

        if (!file) {
            setAvatarPreview(user?.avatar_url ?? null);
            return;
        }

        const localUrl = URL.createObjectURL(file);
        localPreviewRef.current = localUrl;
        setAvatarPreview(localUrl);
    };

    const removeAvatar = () => {
        setData('avatar', null);
        setData('remove_avatar', true);
        setAvatarPreview(null);
        setSelectedFileName('');

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }

        if (localPreviewRef.current) {
            URL.revokeObjectURL(localPreviewRef.current);
            localPreviewRef.current = null;
        }
    };

    const submit = (e) => {
        e.preventDefault();

        post(route('profile.update'), {
            forceFormData: true,
            onSuccess: () => {
                if (localPreviewRef.current) {
                    URL.revokeObjectURL(localPreviewRef.current);
                    localPreviewRef.current = null;
                }
                setSelectedFileName('');
            },
            onFinish: () => {
                setData('avatar', null);
                setData('remove_avatar', false);
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Dati account
                </h2>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel value="Foto profilo" />
                    <div className="mt-2 flex flex-wrap items-center gap-4">
                        <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-full bg-slate-200 text-lg font-semibold text-slate-700">
                            {avatarPreview ? (
                                <img
                                    src={avatarPreview}
                                    alt={user?.name ?? 'Avatar'}
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                String(user?.name ?? 'U').charAt(0).toUpperCase()
                            )}
                        </div>

                        <div className="space-y-2">
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                onChange={onAvatarChange}
                                className="hidden"
                            />
                            <div className="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => fileInputRef.current?.click()}
                                    className="rounded-lg border border-slate-200 bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200"
                                >
                                    Carica foto
                                </button>
                                <button
                                    type="button"
                                    onClick={removeAvatar}
                                    className="text-xs font-semibold text-slate-500 hover:text-slate-700"
                                >
                                    Rimuovi foto
                                </button>
                            </div>
                            {selectedFileName !== '' && (
                                <p className="max-w-[26rem] truncate text-xs text-slate-500">
                                    {selectedFileName}
                                </p>
                            )}
                        </div>
                    </div>
                    <p className="mt-2 text-xs text-slate-500">
                        JPG, PNG o WEBP. Max 2 MB.
                    </p>
                    <InputError className="mt-2" message={errors.avatar} />
                </div>

                <div>
                    <InputLabel htmlFor="name" value="Nome" />

                    <TextInput
                        id="name"
                        className="mt-1 block w-full"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        isFocused
                        autoComplete="name"
                    />

                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1 block w-full bg-slate-100 text-slate-500"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        readOnly
                        autoComplete="username"
                    />

                    <InputError className="mt-2" message={errors.email} />
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div>
                        <p className="mt-2 text-sm text-gray-800">
                            La tua email non e verificata.
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Clicca qui per inviare di nuovo la verifica.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="mt-2 text-sm font-medium text-green-600">
                                Un nuovo link di verifica e stato inviato alla
                                tua email.
                            </div>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Salva dati</PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">Salvato.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
