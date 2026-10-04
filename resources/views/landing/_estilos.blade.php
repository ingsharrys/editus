<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #00024f; --navy-2: #0b1560; --azul: #1b3bff; --azul-claro: #e8ecff; --rojo: #e11d48;
    --fondo: #f6f7fb; --texto: #0f172a; --suave: #5b6478; --borde: #e3e6ef; --blanco: #fff; --verde: #059669;
    --radio: 18px; --sombra: 0 1px 2px rgba(15,23,42,.06), 0 8px 24px rgba(15,23,42,.06);
  }
  * { box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body { margin: 0; font-family: 'Instrument Sans', system-ui, -apple-system, 'Segoe UI', sans-serif; color: var(--texto); background: var(--blanco); line-height: 1.55; -webkit-font-smoothing: antialiased; }
  a { color: inherit; text-decoration: none; }
  img { max-width: 100%; display: block; }
  .contenedor { width: 100%; max-width: 1160px; margin: 0 auto; padding: 0 20px; }
  .eyebrow { font-size: 12px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--azul); }
  h1, h2, h3 { line-height: 1.12; letter-spacing: -.02em; margin: 0; }
  h2 { font-size: clamp(28px, 4vw, 40px); font-weight: 700; color: var(--navy); }
  .lead { font-size: 18px; color: var(--suave); max-width: 640px; }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 48px; padding: 0 22px; border-radius: 12px; font-weight: 600; font-size: 15px; border: 1px solid transparent; cursor: pointer; transition: transform .15s ease, box-shadow .15s ease, background .15s ease; font-family: inherit; }
  .btn:hover { transform: translateY(-1px); }
  .btn-primario { background: var(--azul); color: #fff; box-shadow: 0 8px 20px rgba(27,59,255,.28); }
  .btn-primario:hover { background: #142fe0; }
  .btn-claro { background: rgba(255,255,255,.1); color: #fff; border-color: rgba(255,255,255,.25); }
  .btn-borde { background: #fff; color: var(--navy); border-color: var(--borde); }
  .btn-oscuro { background: var(--navy); color: #fff; }
  .chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  /* Navegación */
  .nav { position: sticky; top: 0; z-index: 30; background: rgba(0,2,79,.92); backdrop-filter: saturate(140%) blur(10px); border-bottom: 1px solid rgba(255,255,255,.08); }
  .nav .contenedor { display: flex; align-items: center; justify-content: space-between; height: 68px; gap: 16px; }
  .nav-links { display: flex; gap: 26px; color: #c7cdf7; font-size: 14px; font-weight: 500; }
  .nav-links a:hover { color: #fff; }
  .nav-acciones { display: flex; gap: 10px; align-items: center; }
  .nav-acciones .btn { height: 40px; padding: 0 16px; font-size: 14px; }
  .nav-ingresar { color: #fff; font-size: 14px; font-weight: 600; padding: 0 6px; }
  @media (max-width: 860px) { .nav-links { display: none; } .nav-ingresar { display: none; } }
  /* Hero */
  .hero { background: radial-gradient(1200px 500px at 85% -10%, rgba(27,59,255,.45), transparent 60%), linear-gradient(180deg, var(--navy) 0%, var(--navy-2) 100%); color: #fff; padding: 72px 0 96px; position: relative; overflow: hidden; }
  .hero::before { content: ''; position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px); background-size: 48px 48px; mask-image: linear-gradient(180deg, #000, transparent 85%); pointer-events: none; }
  .hero-grid { position: relative; display: grid; grid-template-columns: 1.05fr 1fr; gap: 56px; align-items: center; }
  .hero h1 { font-size: clamp(34px, 4.8vw, 54px); font-weight: 700; }
  .hero h1 em { font-style: normal; background: linear-gradient(90deg, #8ea2ff, #fff); -webkit-background-clip: text; background-clip: text; color: transparent; }
  .hero p { font-size: 18px; color: #c7cdf7; margin: 20px 0 30px; max-width: 540px; }
  .hero-ctas { display: flex; gap: 12px; flex-wrap: wrap; }
  .hero-datos { display: flex; gap: 28px; margin-top: 36px; color: #c7cdf7; font-size: 14px; flex-wrap: wrap; }
  .hero-datos strong { display: block; color: #fff; font-size: 22px; }
  @media (max-width: 960px) { .hero-grid { grid-template-columns: 1fr; gap: 44px; } .hero { padding: 48px 0 140px; } }
  /* Maqueta del producto */
  .maqueta { position: relative; }
  .ventana { background: #0d1442; border: 1px solid rgba(255,255,255,.12); border-radius: 20px; box-shadow: 0 30px 80px rgba(0,0,0,.45); overflow: hidden; }
  .ventana-barra { display: flex; align-items: center; gap: 6px; padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,.08); }
  .ventana-barra i { width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,.18); display: block; }
  .ventana-barra span { margin-left: 10px; font-size: 12px; color: #9aa4e6; }
  .programa { position: relative; margin: 14px; aspect-ratio: 16/9; border-radius: 12px; overflow: hidden; background: radial-gradient(circle at 30% 35%, #3b4dbf 0%, #1a2266 45%, #0a0f33 100%); }
  .programa .persona { position: absolute; bottom: 0; left: 50%; transform: translateX(-50%); width: 34%; aspect-ratio: 1/1.15; border-radius: 50% 50% 0 0; background: linear-gradient(180deg, #8d9cff, #4255d8); opacity: .85; }
  .programa .persona::before { content: ''; position: absolute; top: -34%; left: 50%; transform: translateX(-50%); width: 46%; aspect-ratio: 1; border-radius: 50%; background: #b4bfff; }
  .programa .pip { position: absolute; right: 10px; top: 10px; width: 28%; aspect-ratio: 16/9; border-radius: 8px; background: linear-gradient(135deg, #f59e0b, #e11d48); border: 2px solid rgba(255,255,255,.7); }
  .en-vivo { position: absolute; left: 10px; top: 10px; background: var(--rojo); color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .08em; padding: 4px 9px; border-radius: 6px; }
  .rotulo { position: absolute; left: 10px; bottom: 12px; background: #fff; color: var(--navy); border-left: 4px solid var(--rojo); padding: 6px 12px; border-radius: 4px; }
  .rotulo b { display: block; font-size: 13px; } .rotulo small { font-size: 11px; color: var(--suave); }
  .destinos { display: flex; gap: 8px; padding: 0 14px 16px; flex-wrap: wrap; }
  .destinos .chip { background: rgba(255,255,255,.08); color: #dfe3ff; border: 1px solid rgba(255,255,255,.12); }
  .destinos .chip b { width: 7px; height: 7px; border-radius: 50%; background: #34d399; display: inline-block; }
  .flotante { position: absolute; background: #fff; color: var(--texto); border-radius: 14px; box-shadow: var(--sombra), 0 20px 40px rgba(0,0,0,.25); padding: 12px 14px; font-size: 13px; }
  .flotante.publicado { left: -26px; bottom: -96px; width: 230px; }
  .flotante.publicado strong { display: block; font-size: 13px; }
  .flotante .fila { display: flex; align-items: center; gap: 8px; margin-top: 7px; color: var(--suave); font-size: 12px; }
  .flotante .ok { color: var(--verde); font-weight: 700; }
  .flotante.espectadores { right: -18px; top: 70px; text-align: center; }
  .flotante.espectadores b { display: block; font-size: 22px; color: var(--navy); }
  @media (max-width: 560px) { .flotante.publicado { left: 8px; bottom: -110px; } .flotante.espectadores { right: 8px; top: 56px; } }
  /* Secciones */
  section { padding: 96px 0; }
  .seccion-titulo { text-align: center; margin: 0 auto 52px; max-width: 760px; }
  .seccion-titulo .lead { margin: 14px auto 0; }
  .franja { background: var(--fondo); }
  .publico { padding: 28px 0; border-bottom: 1px solid var(--borde); }
  .publico .contenedor { display: flex; justify-content: center; gap: 12px 34px; flex-wrap: wrap; color: var(--suave); font-weight: 600; font-size: 15px; }
  .publico span::before { content: '●'; color: var(--azul); margin-right: 8px; font-size: 10px; vertical-align: middle; }
  .funciones { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
  .funcion { background: #fff; border: 1px solid var(--borde); border-radius: var(--radio); padding: 26px; box-shadow: var(--sombra); }
  .funcion .icono { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: var(--azul-claro); color: var(--azul); margin-bottom: 16px; }
  .funcion h3 { font-size: 18px; color: var(--navy); margin-bottom: 8px; }
  .funcion p { margin: 0; color: var(--suave); font-size: 15px; }
  @media (max-width: 960px) { .funciones { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 600px) { .funciones { grid-template-columns: 1fr; } section { padding: 68px 0; } }
  .dividido { display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; }
  .dividido.invertido > :first-child { order: 2; }
  @media (max-width: 900px) { .dividido { grid-template-columns: 1fr; gap: 36px; } .dividido.invertido > :first-child { order: 0; } }
  .lista { list-style: none; padding: 0; margin: 26px 0 0; display: grid; gap: 12px; }
  .lista li { display: flex; gap: 12px; align-items: flex-start; font-size: 16px; }
  .lista li::before { content: '✓'; flex: 0 0 24px; height: 24px; border-radius: 50%; background: #d1fae5; color: var(--verde); font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 13px; }
  .tarjeta-wp { background: #fff; border: 1px solid var(--borde); border-radius: 20px; box-shadow: var(--sombra); overflow: hidden; }
  .tarjeta-wp .cab { display: flex; align-items: center; gap: 10px; padding: 14px 18px; background: #f1f3f9; border-bottom: 1px solid var(--borde); font-weight: 600; color: var(--navy); font-size: 14px; }
  .tarjeta-wp .cuerpo { padding: 20px; display: grid; gap: 12px; }
  .paso-wp { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--borde); border-radius: 12px; font-size: 14px; }
  .paso-wp .n { width: 28px; height: 28px; border-radius: 8px; background: var(--azul-claro); color: var(--azul); font-weight: 700; display: flex; align-items: center; justify-content: center; flex: 0 0 28px; }
  .paso-wp .estado { margin-left: auto; font-size: 12px; font-weight: 700; color: var(--verde); }
  .llave { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; background: var(--fondo); border-radius: 8px; padding: 8px 10px; color: var(--suave); }
  /* Planes */
  .alternar { display: inline-flex; background: #fff; border: 1px solid var(--borde); border-radius: 999px; padding: 4px; margin: 0 auto 36px; }
  .alternar button { border: 0; background: transparent; padding: 10px 20px; border-radius: 999px; font: inherit; font-weight: 600; color: var(--suave); cursor: pointer; }
  .alternar button.activo { background: var(--navy); color: #fff; }
  .alternar .ahorro { color: var(--verde); font-size: 12px; margin-left: 6px; }
  .alternar button.activo .ahorro { color: #a7f3d0; }
  .planes { display: grid; grid-template-columns: repeat(2, minmax(0, 420px)); gap: 22px; justify-content: center; }
  @media (max-width: 860px) { .planes { grid-template-columns: 1fr; } }
  .plan { position: relative; background: #fff; border: 1px solid var(--borde); border-radius: 22px; padding: 30px; box-shadow: var(--sombra); display: flex; flex-direction: column; }
  .plan.destacado { border: 2px solid var(--azul); box-shadow: 0 20px 50px rgba(27,59,255,.18); }
  .plan .insignia { position: absolute; top: -13px; left: 30px; background: var(--azul); color: #fff; }
  .plan h3 { font-size: 22px; color: var(--navy); }
  .plan .para { color: var(--suave); font-size: 14px; margin: 6px 0 20px; }
  .precio { display: flex; align-items: baseline; gap: 6px; }
  .precio b { font-size: 44px; font-weight: 700; color: var(--navy); letter-spacing: -.03em; }
  .precio span { color: var(--suave); font-size: 15px; }
  .precio-nota { font-size: 13px; color: var(--verde); font-weight: 600; min-height: 20px; margin-top: 4px; }
  .plan .lista { margin: 22px 0 26px; flex: 1; }
  .plan .lista li { font-size: 15px; }
  .plan .lista li.no::before { content: '–'; background: #f1f5f9; color: #94a3b8; }
  .plan .lista li.no { color: #94a3b8; }
  .plan .btn { width: 100%; }
  .pagos { text-align: center; color: var(--suave); font-size: 14px; margin-top: 26px; }
  .comparar { margin-top: 56px; background: #fff; border: 1px solid var(--borde); border-radius: 20px; overflow-x: auto; box-shadow: var(--sombra); }
  .comparar table { width: 100%; border-collapse: collapse; font-size: 15px; min-width: 560px; }
  .comparar th, .comparar td { padding: 14px 20px; border-bottom: 1px solid var(--borde); text-align: left; }
  .comparar th { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--suave); background: #fafbfe; }
  .comparar td:not(:first-child), .comparar th:not(:first-child) { text-align: center; }
  .comparar tr:last-child td { border-bottom: 0; }
  .si { color: var(--verde); font-weight: 700; } .no-c { color: #cbd5e1; }
  /* Pasos */
  .pasos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; counter-reset: paso; }
  .paso { padding: 26px; border-radius: var(--radio); border: 1px solid var(--borde); background: #fff; }
  .paso::before { counter-increment: paso; content: counter(paso); width: 36px; height: 36px; border-radius: 10px; background: var(--navy); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-bottom: 16px; }
  .paso h3 { font-size: 18px; color: var(--navy); margin-bottom: 6px; } .paso p { margin: 0; color: var(--suave); font-size: 15px; }
  @media (max-width: 800px) { .pasos { grid-template-columns: 1fr; } }
  /* Preguntas */
  .faq { max-width: 820px; margin: 0 auto; display: grid; gap: 12px; }
  .faq details { background: #fff; border: 1px solid var(--borde); border-radius: 14px; padding: 18px 22px; }
  .faq summary { cursor: pointer; font-weight: 600; color: var(--navy); list-style: none; display: flex; justify-content: space-between; gap: 16px; }
  .faq summary::-webkit-details-marker { display: none; }
  .faq summary::after { content: '+'; font-size: 22px; line-height: 1; color: var(--azul); }
  .faq details[open] summary::after { content: '–'; }
  .faq p { margin: 12px 0 0; color: var(--suave); }
  /* CTA y pie */
  .cta { background: linear-gradient(135deg, var(--navy), #1a2bb8); color: #fff; border-radius: 26px; padding: 56px; display: flex; align-items: center; justify-content: space-between; gap: 28px; flex-wrap: wrap; }
  .cta h2 { color: #fff; } .cta p { color: #c7cdf7; margin: 10px 0 0; }
  @media (max-width: 600px) { .cta { padding: 34px 24px; } }
  footer { background: var(--navy); color: #c7cdf7; padding: 56px 0 30px; font-size: 14px; }
  .pie { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 32px; }
  .pie h4 { color: #fff; margin: 0 0 12px; font-size: 14px; }
  .pie a { display: block; margin-bottom: 8px; } .pie a:hover { color: #fff; }
  .pie-legal { border-top: 1px solid rgba(255,255,255,.12); margin-top: 36px; padding-top: 20px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 13px; }
  @media (max-width: 760px) { .pie { grid-template-columns: 1fr; } }
  :focus-visible { outline: 3px solid #8ea2ff; outline-offset: 2px; border-radius: 6px; }
</style>
