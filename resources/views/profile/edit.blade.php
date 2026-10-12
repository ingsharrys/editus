@extends('layouts.app')

@section('content')
@php
    $foto = $user->avatarUrl();
    $rol = $user->role;
    $cuantos = count($user->permisos());
    $input = 'w-full h-11 rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500';
    $etiqueta = 'block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1';
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6">
        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Mi cuenta</div>
        <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Mi perfil</h1>
        <p class="mt-1.5 text-sm text-gray-500">Tus datos, tu foto, tu contraseña y lo que puedes hacer en editus.</p>
    </div>

    @if (session('status') === 'profile-updated')<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">Perfil guardado.</div>@endif
    @if (session('status') === 'password-updated')<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">Contraseña actualizada.</div>@endif

    <div class="grid lg:grid-cols-[300px_minmax(0,1fr)] gap-5 items-start">
        {{-- Tarjeta de la persona --}}
        <aside class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden lg:sticky lg:top-6">
            <div class="h-20 bg-gradient-to-r from-[#00024f] via-indigo-700 to-[#183EEB]"></div>
            <div class="px-5 pb-5 -mt-12 text-center">
                <div class="mx-auto h-24 w-24 rounded-full ring-4 ring-white bg-indigo-100 overflow-hidden flex items-center justify-center text-2xl font-bold text-indigo-700">
                    @if ($foto)<img src="{{ $foto }}" alt="" class="h-full w-full object-cover">@else{{ $user->iniciales() }}@endif
                </div>
                <h2 class="mt-3 text-lg font-bold text-gray-900 break-words">{{ $user->name }}</h2>
                @if ($user->cargo)<p class="text-sm text-gray-500">{{ $user->cargo }}</p>@endif
                <div class="mt-2 flex justify-center flex-wrap gap-1.5">
                    <span class="rounded-full {{ $user->isAdmin() ? 'bg-purple-50 text-purple-700' : 'bg-indigo-50 text-indigo-700' }} px-3 py-1 text-xs font-semibold">{{ $rol->name ?? 'Sin rol' }}</span>
                    <span class="rounded-full bg-emerald-50 text-emerald-700 px-3 py-1 text-xs font-semibold">Activo</span>
                </div>
                <dl class="mt-4 text-left text-sm divide-y divide-gray-100 border-t border-gray-100">
                    <div class="py-2.5 flex justify-between gap-3"><dt class="text-gray-500">Correo</dt><dd class="text-gray-800 truncate">{{ $user->email }}</dd></div>
                    @if ($user->telefono)<div class="py-2.5 flex justify-between gap-3"><dt class="text-gray-500">Teléfono</dt><dd class="text-gray-800">{{ $user->telefono }}</dd></div>@endif
                    <div class="py-2.5 flex justify-between gap-3"><dt class="text-gray-500">Miembro desde</dt><dd class="text-gray-800">{{ optional($user->created_at)->format('d/m/Y') }}</dd></div>
                    <div class="py-2.5 flex justify-between gap-3"><dt class="text-gray-500">Permisos</dt><dd class="text-gray-800">{{ $cuantos }} de {{ $totalPermisos }}</dd></div>
                </dl>
            </div>
        </aside>

        <div class="space-y-5 min-w-0">
            {{-- Datos personales --}}
            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 sm:p-6" x-data="{ vista: @js($foto), quitar: false }">
                <h3 class="text-base font-bold text-gray-900">Datos personales</h3>
                <p class="text-sm text-gray-500 mt-0.5">Así te ven tus compañeros en editus.</p>
                <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="mt-5 space-y-4">
                    @csrf
                    @method('patch')
                    <input type="hidden" name="quitar_avatar" :value="quitar ? 1 : 0">
                    <div class="flex items-center gap-4">
                        <div class="h-16 w-16 shrink-0 rounded-full bg-indigo-100 overflow-hidden flex items-center justify-center text-lg font-bold text-indigo-700">
                            <template x-if="vista"><img :src="vista" alt="" class="h-full w-full object-cover"></template>
                            <template x-if="!vista"><span>{{ $user->iniciales() }}</span></template>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <label class="h-10 inline-flex items-center rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer">
                                Cambiar foto
                                <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" class="hidden" x-ref="foto" @change="const a = $event.target.files[0]; if (a) { vista = URL.createObjectURL(a); quitar = false; }">
                            </label>
                            <button type="button" x-show="vista" @click="vista = null; quitar = true; $refs.foto.value = ''" class="h-10 rounded-xl px-3 text-sm font-semibold text-rose-700 hover:bg-rose-50">Quitar</button>
                        </div>
                        <span class="hidden sm:block text-xs text-gray-400">JPG, PNG o WebP · máx. 4 MB</span>
                    </div>
                    <x-input-error :messages="$errors->get('avatar')" />

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label for="name" class="{{ $etiqueta }}">Nombre</label>
                            <input id="name" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name" class="{{ $input }}">
                            <x-input-error class="mt-1" :messages="$errors->get('name')" />
                        </div>
                        <div>
                            <label for="email" class="{{ $etiqueta }}">Correo electrónico</label>
                            <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username" class="{{ $input }}">
                            <x-input-error class="mt-1" :messages="$errors->get('email')" />
                        </div>
                        <div>
                            <label for="cargo" class="{{ $etiqueta }}">Cargo</label>
                            <input id="cargo" name="cargo" value="{{ old('cargo', $user->cargo) }}" maxlength="100" placeholder="Periodista, Director, Community manager…" class="{{ $input }}">
                            <x-input-error class="mt-1" :messages="$errors->get('cargo')" />
                        </div>
                        <div>
                            <label for="telefono" class="{{ $etiqueta }}">Teléfono</label>
                            <input id="telefono" name="telefono" type="tel" value="{{ old('telefono', $user->telefono) }}" maxlength="40" placeholder="+57 300 000 0000" class="{{ $input }}">
                            <x-input-error class="mt-1" :messages="$errors->get('telefono')" />
                        </div>
                    </div>

                    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                        <p class="text-sm text-amber-700">Tu correo no está verificado. <button form="send-verification" class="underline">Reenviar el enlace</button></p>
                        @if (session('status') === 'verification-link-sent')<p class="text-sm text-emerald-700">Te enviamos un nuevo enlace de verificación.</p>@endif
                    @endif

                    <div class="flex justify-end"><button class="h-11 rounded-xl bg-[#00024f] text-white px-6 text-sm font-semibold hover:opacity-90">Guardar cambios</button></div>
                </form>
                <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>
            </section>

            {{-- Contraseña --}}
            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 sm:p-6">
                <h3 class="text-base font-bold text-gray-900">Contraseña</h3>
                <p class="text-sm text-gray-500 mt-0.5">Usa una contraseña larga que no uses en otros sitios.</p>
                <form method="post" action="{{ route('password.update') }}" class="mt-5 space-y-4">
                    @csrf
                    @method('put')
                    <div class="grid sm:grid-cols-3 gap-4">
                        <div>
                            <label for="current_password" class="{{ $etiqueta }}">Contraseña actual</label>
                            <input id="current_password" name="current_password" type="password" autocomplete="current-password" class="{{ $input }}">
                            <x-input-error class="mt-1" :messages="$errors->updatePassword->get('current_password')" />
                        </div>
                        <div>
                            <label for="password" class="{{ $etiqueta }}">Nueva contraseña</label>
                            <input id="password" name="password" type="password" autocomplete="new-password" class="{{ $input }}">
                            <x-input-error class="mt-1" :messages="$errors->updatePassword->get('password')" />
                        </div>
                        <div>
                            <label for="password_confirmation" class="{{ $etiqueta }}">Confirmar</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="{{ $input }}">
                            <x-input-error class="mt-1" :messages="$errors->updatePassword->get('password_confirmation')" />
                        </div>
                    </div>
                    <div class="flex justify-end"><button class="h-11 rounded-xl border border-gray-200 px-6 text-sm font-semibold text-gray-800 hover:bg-gray-50">Cambiar contraseña</button></div>
                </form>
            </section>

            {{-- Mi rol y permisos --}}
            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Mi rol y permisos</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Rol <b class="text-gray-800">{{ $rol->name ?? 'sin rol' }}</b>{{ $rol?->descripcion ? ' · ' . rtrim($rol->descripcion, '.') : '' }}. Si necesitas algo más, pídeselo a un administrador.</p>
                    </div>
                    @if ($user->tienePermiso('roles'))<a href="{{ route('roles.index') }}" class="text-sm font-semibold text-indigo-700 hover:underline">Administrar roles →</a>@endif
                </div>
                @if ($grupos)
                    <div class="mt-4 grid sm:grid-cols-2 gap-3">
                        @foreach ($grupos as $grupo => $permisos)
                            <div class="rounded-xl bg-gray-50 p-3.5">
                                <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-2">{{ $grupo }}</div>
                                <ul class="space-y-1.5">
                                    @foreach ($permisos as [$nombre, $detalle])
                                        <li class="flex items-start gap-2 text-sm"><svg class="h-4 w-4 mt-0.5 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg><span><span class="text-gray-800">{{ $nombre }}</span><span class="block text-xs text-gray-500">{{ $detalle }}</span></span></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-4 rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">Tu rol todavía no tiene permisos. Por ahora solo puedes ver y editar tu perfil.</p>
                @endif
            </section>

            {{-- Eliminar cuenta --}}
            <section class="rounded-2xl border border-rose-200 bg-white shadow-sm p-5 sm:p-6">
                <h3 class="text-base font-bold text-rose-700">Eliminar mi cuenta</h3>
                <p class="text-sm text-gray-500 mt-0.5">Se borran tu cuenta y tus datos para siempre. Esto no se puede deshacer.</p>
                <button type="button" x-data @click.prevent="$dispatch('open-modal', 'confirm-user-deletion')" class="mt-4 h-10 rounded-xl border border-rose-200 px-4 text-sm font-semibold text-rose-700 hover:bg-rose-50">Eliminar cuenta</button>
                <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
                    <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
                        @csrf
                        @method('delete')
                        <h2 class="text-lg font-bold text-gray-900">¿Seguro que quieres eliminar tu cuenta?</h2>
                        <p class="mt-1 text-sm text-gray-600">Escribe tu contraseña para confirmar.</p>
                        <input name="password" type="password" placeholder="Contraseña" class="mt-4 {{ $input }}">
                        <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
                        <div class="mt-6 flex justify-end gap-2">
                            <button type="button" x-on:click="$dispatch('close')" class="h-10 rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-700">Cancelar</button>
                            <button class="h-10 rounded-xl bg-rose-600 text-white px-4 text-sm font-semibold">Eliminar cuenta</button>
                        </div>
                    </form>
                </x-modal>
            </section>
        </div>
    </div>
</div>
@endsection
