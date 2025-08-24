<link rel="stylesheet" href="https://unpkg.com/boxicons@2.0.7/css/boxicons.min.css" />

@auth
    <div class="min-h-screen flex flex-row bg-gray-200 mt-[50px]">
        <div class="flex flex-col w-56 bg-white rounded-r-3xl shadow-xl overflow-hidden">
            <ul class="flex flex-col py-4">
                <li>
                    <a href="{{ route('meta.pages.index') }}"
                        class="flex flex-row items-center pl-2 pr-4 h-12 transform hover:translate-x-2 transition-transform ease-in duration-200  text-gray-500 hover:text-gray-800">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                            class="bi bi-facebook" color="blue" viewBox="0 0 16 16">
                            <path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275
                   c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157
                   1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303
                   1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604
                   6.75-3.934 6.75-7.951" />
                        </svg>
                        <span class="text-sm font-medium text-black ml-2">Mis Paginas</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
@endauth
