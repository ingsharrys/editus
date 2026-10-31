import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Formularios grandes (data-empaquetar): se envían en un solo campo para no superar el límite de campos de PHP
document.addEventListener('submit', (e) => {
    const f = e.target;
    if (!(f instanceof HTMLFormElement) || !f.hasAttribute('data-empaquetar') || e.defaultPrevented) return;
    if ((f.method || '').toLowerCase() !== 'post' || f.querySelector('input[type=file]')) return;
    e.preventDefault();
    const pares = [];
    for (const [k, v] of new FormData(f, e.submitter || undefined)) if (typeof v === 'string' && k !== '_token' && k !== '_method') pares.push([k, v]);
    const nuevo = document.createElement('form');
    nuevo.method = 'POST';
    nuevo.action = f.action;
    nuevo.style.display = 'none';
    const campo = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; nuevo.appendChild(i); };
    campo('_token', f.querySelector('input[name=_token]')?.value || document.querySelector('meta[name=csrf-token]')?.content || '');
    const metodo = f.querySelector('input[name=_method]')?.value;
    if (metodo) campo('_method', metodo);
    campo('_empaquetado', JSON.stringify(pares));
    document.body.appendChild(nuevo);
    f.querySelectorAll('button[type=submit], button:not([type])').forEach((b) => { b.disabled = true; });
    nuevo.submit();
});
