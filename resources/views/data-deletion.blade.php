<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Editus | Eliminación de Datos</title>
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/png">

    <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    <!-- Tailwind CDN -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <meta name="description" content="Instrucciones para solicitar la eliminación de datos personales de la plataforma Editus.">
    <meta name="robots" content="index,follow">
  </head>

  <body class="bg-gray-100 text-gray-800">
      <div class="max-w-4xl mx-auto px-6 py-12">

        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">

            <!-- Encabezado -->

            <div class="bg-red-600 text-white p-10">

                <h1 class="text-4xl font-bold">
                    Eliminación de Datos
                </h1>

                <p class="mt-3 text-red-100">
                    Aplicación: <strong>Editus</strong>
                </p>

                <p class="text-red-100">
                    Desarrollado por <strong>Sharrys Tech</strong>
                </p>

            </div>

            <div class="p-10 space-y-10">

                <!-- Introducción -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Solicitud de eliminación de datos
                    </h2>

                    <p class="leading-8">
                        En <strong>Editus</strong> respetamos el derecho de los
                        usuarios a solicitar la eliminación de la información
                        recopilada y almacenada durante el uso de nuestra
                        plataforma, incluyendo los datos obtenidos mediante la
                        integración con Facebook y otros servicios de Meta.
                    </p>

                </section>

                <!-- Método 1 -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Opción 1: Eliminar tu cuenta desde Editus
                    </h2>

                    <p class="leading-8">
                        Si tienes acceso a tu cuenta, puedes solicitar la
                        eliminación de tus datos eliminando tu cuenta desde la
                        configuración del perfil. Una vez confirmada la
                        eliminación, se iniciará el proceso de eliminación de la
                        información asociada a tu cuenta, incluyendo los datos
                        sincronizados con Facebook, salvo aquellos que debamos
                        conservar por obligaciones legales.
                    </p>

                </section>

                <!-- Método 2 -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Opción 2: Solicitud por correo electrónico
                    </h2>

                    <p class="leading-8">
                        Si no puedes acceder a tu cuenta o prefieres realizar la
                        solicitud manualmente, puedes escribirnos al siguiente
                        correo electrónico:
                    </p>

                    <div class="mt-6 rounded-lg border bg-gray-50 p-6">

                        <p class="font-semibold">
                            Correo de contacto
                        </p>

                        <a href="mailto:dario.charry.ramos@gmail.com"
                            class="text-blue-600 hover:underline">
                            dario.charry.ramos@gmail.com
                        </a>

                    </div>

                </section>

                <!-- Información requerida -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Información que debes incluir
                    </h2>

                    <p class="mb-4">
                        Para procesar tu solicitud de forma segura, incluye:
                    </p>

                    <ul class="list-disc ml-8 space-y-2">

                        <li>Nombre completo.</li>

                        <li>Correo electrónico registrado en Editus.</li>

                        <li>Nombre de la página de Facebook vinculada (si aplica).</li>

                        <li>Descripción de la solicitud.</li>

                    </ul>

                </section>

                <!-- Qué se elimina -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Datos que serán eliminados
                    </h2>

                    <p class="mb-4">
                        Cuando la solicitud sea aprobada, eliminaremos, según
                        corresponda:
                    </p>

                    <ul class="list-disc ml-8 space-y-2">

                        <li>Información de la cuenta de Editus.</li>

                        <li>Nombre del usuario.</li>

                        <li>Correo electrónico.</li>

                        <li>Identificador de Facebook almacenado.</li>

                        <li>Tokens de acceso asociados a Meta.</li>

                        <li>Información sincronizada de páginas autorizadas.</li>

                        <li>Información relacionada con catálogos comerciales.</li>

                    </ul>

                </section>

                <!-- Tiempo -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Tiempo de procesamiento
                    </h2>

                    <p class="leading-8">
                        Las solicitudes serán atendidas en un plazo razonable,
                        normalmente dentro de los <strong>30 días calendario</strong>
                        siguientes a la recepción de la solicitud, salvo que la
                        legislación aplicable establezca un término diferente.
                    </p>

                </section>

                <!-- Excepciones -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Información que podría conservarse
                    </h2>

                    <p class="leading-8">
                        En algunos casos podremos conservar determinada
                        información cuando sea necesaria para cumplir con
                        obligaciones legales, resolver disputas, prevenir fraude,
                        garantizar la seguridad de la plataforma o hacer cumplir
                        nuestros términos y condiciones.
                    </p>

                </section>

                <!-- Facebook -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Revocación de permisos en Facebook
                    </h2>

                    <p class="leading-8">
                        También puedes revocar el acceso de Editus desde la
                        configuración de aplicaciones de tu cuenta de Facebook.
                        Una vez revocados los permisos, la aplicación dejará de
                        acceder a la información autorizada por Meta. Si además
                        deseas que eliminemos los datos previamente almacenados,
                        deberás seguir alguno de los procedimientos descritos en
                        esta página.
                    </p>

                </section>

                <!-- Contacto -->

                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        Contacto
                    </h2>

                    <div class="rounded-lg border bg-gray-50 p-6 space-y-2">

                        <p>
                            <strong>Sharrys Tech</strong>
                        </p>

                        <p>
                            Plataforma: <strong>Editus</strong>
                        </p>

                        <p>
                            Neiva, Huila, Colombia
                        </p>

                        <p>
                            <a href="mailto:dario.charry.ramos@gmail.com"
                                class="text-blue-600 hover:underline">
                                dario.charry.ramos@gmail.com
                            </a>
                        </p>

                        <p>
                            <a href="https://app.editus.online/"
                                class="text-blue-600 hover:underline">
                                https://app.editus.online/
                            </a>
                        </p>

                    </div>

                </section>

            </div>

            <div class="border-t bg-gray-50 px-10 py-6 text-center text-sm text-gray-500">

                © {{ date('Y') }} Sharrys Tech · Editus · Todos los derechos reservados.

            </div>

        </div>

    </div>
  </body>
</html>

