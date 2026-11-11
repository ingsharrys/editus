{{-- Miniatura del anuncio: si la imagen pesa más de 2 MB (límite de YouTube) o es muy grande, se reduce y se comprime
     aquí mismo a JPG (máximo 1920×1080, sin recortar) antes de enviarla. Devuelve el data URL listo para mandar. --}}
<script>
window.prepararMiniatura = async function (archivo) {
  const MAX = 1.9 * 1024 * 1024, ANCHO = 1920, ALTO = 1080;
  const TX = @js([
    'noImagen' => __('La miniatura debe ser una imagen JPG o PNG.'),
    'pequena' => __('La miniatura es muy pequeña: usa al menos 1280×720 (mínimo 640×360).'),
    'noSeReduce' => __('No se pudo reducir la miniatura. Usa una imagen JPG de 1280×720.'),
  ]);
  if (!/^image\/(jpeg|png)$/.test(archivo.type)) throw new Error(TX.noImagen);
  const leer = (blob) => new Promise((ok, mal) => { const fr = new FileReader(); fr.onload = () => ok(fr.result); fr.onerror = mal; fr.readAsDataURL(blob); });
  const original = await leer(archivo);
  const img = await new Promise((ok, mal) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => mal(new Error(TX.noImagen)); i.src = original; });
  if (img.naturalWidth < 640 || img.naturalHeight < 360) throw new Error(TX.pequena);
  if (archivo.size <= MAX && img.naturalWidth <= ANCHO && img.naturalHeight <= ALTO) return original;

  let escala = Math.min(1, ANCHO / img.naturalWidth, ALTO / img.naturalHeight);
  for (let intento = 0; intento < 6; intento++) {
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(img.naturalWidth * escala)); c.height = Math.max(1, Math.round(img.naturalHeight * escala));
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, c.width, c.height); // un PNG transparente no queda negro en JPG
    ctx.drawImage(img, 0, 0, c.width, c.height);
    for (const calidad of [0.9, 0.82, 0.74, 0.66]) {
      const datos = c.toDataURL('image/jpeg', calidad);
      if ((datos.length - datos.indexOf(',') - 1) * 0.75 <= MAX) return datos;
    }
    escala *= 0.8;
  }
  throw new Error(TX.noSeReduce);
};
</script>
