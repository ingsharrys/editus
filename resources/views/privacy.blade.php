<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Editus | Política de Privacidad</title>
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/png">

    <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="description" content="Política de Privacidad de Editus, plataforma desarrollada por Sharrys Tech para la gestión e integración con Facebook.">
    <meta name="robots" content="index,follow">
  </head>

  <body class="bg-gray-100 text-gray-800">
      
      <div class="max-w-5xl mx-auto px-6 py-12">

        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">

            <!-- Encabezado -->
            <div class="bg-blue-700 text-white p-10">

                <h1 class="text-4xl font-bold">
                    Política de Privacidad
                </h1>

                <p class="mt-3 text-blue-100">
                    Aplicación: <strong>Editus</strong>
                </p>

                <p class="text-blue-100">
                    Desarrollado por <strong>Sharrys Tech</strong>
                </p>

                <p class="text-blue-100">
                    Última actualización: 23 de julio de 2026
                </p>

            </div>

            <div class="p-10 space-y-10">

                <!-- Introducción -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        1. Introducción
                    </h2>

                    <p class="leading-8">
                        En <strong>Editus</strong>, desarrollado por
                        <strong>Sharrys Tech</strong>, respetamos la privacidad de
                        nuestros usuarios y nos comprometemos a proteger la
                        información personal recopilada a través de nuestra
                        plataforma.
                    </p>

                    <p class="leading-8 mt-4">
                        Esta Política de Privacidad explica cómo recopilamos,
                        almacenamos, utilizamos y protegemos la información
                        obtenida de nuestros usuarios, incluyendo aquella
                        autorizada mediante las APIs de Meta Platforms
                        (Facebook).
                    </p>

                </section>

                <!-- Responsable -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        2. Responsable del tratamiento de los datos
                    </h2>

                    <div class="space-y-2">

                        <p><strong>Empresa:</strong> Sharrys Tech</p>

                        <p><strong>Aplicación:</strong> Editus</p>

                        <p><strong>Sitio web:</strong>
                            <a href="https://app.editus.online/"
                                class="text-blue-600 hover:underline">
                                https://app.editus.online/
                            </a>
                        </p>

                        <p><strong>Ubicación:</strong>
                            Neiva, Huila, Colombia
                        </p>

                        <p><strong>Correo:</strong>
                            <a href="mailto:dario.charry.ramos@gmail.com"
                                class="text-blue-600 hover:underline">
                                dario.charry.ramos@gmail.com
                            </a>
                        </p>

                    </div>

                </section>

                <!-- Información recopilada -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        3. Información que recopilamos
                    </h2>

                    <p class="mb-4">
                        Dependiendo de las autorizaciones concedidas por el
                        usuario, Editus puede recopilar la siguiente información:
                    </p>

                    <ul class="list-disc ml-8 space-y-2">

                        <li>Nombre del usuario.</li>

                        <li>Correo electrónico.</li>

                        <li>Identificador de Facebook.</li>

                        <li>Fotografía de perfil.</li>

                        <li>Fan Pages administradas.</li>

                        <li>Información relacionada con catálogos comerciales.</li>

                        <li>Información necesaria para gestionar las páginas
                            autorizadas por el usuario.</li>

                    </ul>

                </section>

                <!-- Permisos -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        4. Permisos solicitados a Facebook
                    </h2>

                    <p class="mb-4">
                        Editus utiliza la API oficial de Meta y únicamente
                        solicita permisos necesarios para prestar sus servicios.
                    </p>

                    <div class="overflow-x-auto">

                        <table class="min-w-full border border-gray-300">

                            <thead class="bg-gray-100">

                                <tr>

                                    <th class="border px-4 py-3 text-left">
                                        Permiso
                                    </th>

                                    <th class="border px-4 py-3 text-left">
                                        Finalidad
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <tr>

                                    <td class="border px-4 py-3">
                                        public_profile
                                    </td>

                                    <td class="border px-4 py-3">
                                        Identificar al usuario autenticado.
                                    </td>

                                </tr>

                                <tr>

                                    <td class="border px-4 py-3">
                                        email
                                    </td>

                                    <td class="border px-4 py-3">
                                        Identificación y comunicación con el
                                        usuario.
                                    </td>

                                </tr>

                                <tr>

                                    <td class="border px-4 py-3">
                                        pages_show_list
                                    </td>

                                    <td class="border px-4 py-3">
                                        Obtener las páginas administradas por el
                                        usuario para permitir su sincronización.
                                    </td>

                                </tr>

                                <tr>

                                    <td class="border px-4 py-3">
                                        business_management
                                    </td>

                                    <td class="border px-4 py-3">
                                        Gestionar los activos comerciales
                                        autorizados por el usuario.
                                    </td>

                                </tr>

                                <tr>

                                    <td class="border px-4 py-3">
                                        catalog_management
                                    </td>

                                    <td class="border px-4 py-3">
                                        Gestionar catálogos asociados a las
                                        cuentas comerciales autorizadas.
                                    </td>

                                </tr>

                            </tbody>

                        </table>

                    </div>

                </section>

                <!-- Uso -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        5. Uso de la información
                    </h2>

                    <p class="mb-4">
                        La información recopilada se utiliza únicamente para:
                    </p>

                    <ul class="list-disc ml-8 space-y-2">

                        <li>Autenticar al usuario.</li>

                        <li>Sincronizar cuentas y páginas de Facebook.</li>

                        <li>Gestionar catálogos comerciales autorizados.</li>

                        <li>Ofrecer funcionalidades de la plataforma.</li>

                        <li>Mejorar el rendimiento y la experiencia del usuario.</li>

                        <li>Brindar soporte técnico.</li>

                        <li>Cumplir obligaciones legales.</li>

                    </ul>

                </section>

                <!-- Almacenamiento -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        6. Conservación de los datos
                    </h2>

                    <p class="leading-8">
                        La información se almacena únicamente durante el tiempo
                        necesario para prestar los servicios ofrecidos por
                        Editus o hasta que el usuario solicite la eliminación de
                        su cuenta y de la información asociada, salvo cuando una
                        obligación legal exija un periodo de conservación mayor.
                    </p>

                </section>

                <!-- Compartición -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        7. Compartición de información
                    </h2>

                    <p class="leading-8">
                        Editus no vende, alquila ni comercializa información
                        personal de sus usuarios. Los datos únicamente podrán
                        compartirse cuando exista una obligación legal o cuando
                        sea necesario para el funcionamiento de servicios
                        tecnológicos utilizados por la plataforma.
                    </p>

                </section>

                <!-- Seguridad -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        8. Seguridad de la información
                    </h2>

                    <p class="leading-8">
                        Implementamos medidas técnicas y organizativas
                        razonables para proteger la información frente a accesos
                        no autorizados, alteración, pérdida o divulgación.
                        Ningún sistema es completamente seguro, pero trabajamos
                        continuamente para mantener un nivel adecuado de
                        protección.
                    </p>

                </section>

                <!-- Analytics -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        9. Servicios de terceros
                    </h2>

                    <p class="leading-8">
                        Editus utiliza servicios de terceros como Google
                        Analytics para analizar el uso de la plataforma y mejorar
                        la experiencia de navegación. Asimismo, utiliza los
                        servicios y APIs oficiales de Meta Platforms para la
                        autenticación e integración con Facebook.
                    </p>

                </section>

                <!-- Derechos -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        10. Derechos del usuario
                    </h2>

                    <p class="mb-4">
                        El usuario podrá en cualquier momento:
                    </p>

                    <ul class="list-disc ml-8 space-y-2">

                        <li>Solicitar acceso a sus datos.</li>

                        <li>Solicitar la corrección de información.</li>

                        <li>Solicitar la eliminación de su información.</li>

                        <li>Revocar los permisos otorgados a Facebook.</li>

                        <li>Eliminar su cuenta dentro de la plataforma.</li>

                    </ul>

                </section>

                <!-- Eliminación -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        11. Eliminación de datos
                    </h2>

                    <p class="leading-8">
                        Los usuarios pueden solicitar la eliminación de su
                        información eliminando su cuenta desde la plataforma o
                        comunicándose al correo
                        <strong>dario.charry.ramos@gmail.com</strong>. Una vez
                        recibida la solicitud, la información será eliminada
                        dentro de un plazo razonable, salvo que exista una
                        obligación legal que requiera conservar determinados
                        registros.
                    </p>

                </section>

                <!-- Meta -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        12. Cumplimiento con Meta Platforms
                    </h2>

                    <p class="leading-8">
                        Editus utiliza las APIs oficiales de Meta Platforms y
                        cumple con las políticas para desarrolladores y con las
                        condiciones de uso aplicables al tratamiento de datos
                        obtenidos mediante Facebook Login y Graph API.
                    </p>

                </section>

                <!-- Cambios -->
                <section>

                    <h2 class="text-2xl font-semibold mb-4">
                        13. Cambios en esta política
                    </h2>

                    <p class="leading-8">
                        Esta Política de Privacidad podrá actualizarse en
                        cualquier momento para reflejar cambios legales,
                        tecnológicos o funcionales de la plataforma. La versión
                        vigente siempre estará disponible en esta página.
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

