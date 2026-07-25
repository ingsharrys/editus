-- =====================================================================
-- Optimización de editus_mediasocial (MariaDB/MySQL en cPanel)
-- NOTA: esto mismo lo hace `php artisan migrate` con la migración
-- 2026_07_25_000002_add_performance_indexes. Usa este script SOLO si
-- prefieres aplicarlo a mano desde phpMyAdmin.
-- =====================================================================

-- Índices para las consultas más frecuentes de la app
ALTER TABLE meta_posts
  ADD INDEX IF NOT EXISTS idx_posts_page_published (meta_page_id, published_at),
  ADD INDEX IF NOT EXISTS idx_posts_status_published (status, published_at),
  ADD INDEX IF NOT EXISTS idx_posts_fb_post_id (fb_post_id);

ALTER TABLE meta_page_user
  ADD INDEX IF NOT EXISTS idx_pivot_page_active_updated (meta_page_id, is_active, updated_at);

-- ---------------------------------------------------------------------
-- Mantenimiento (opcional, ejecutar de vez en cuando)
-- ---------------------------------------------------------------------

-- Limpia trabajos fallidos de más de 30 días
DELETE FROM failed_jobs WHERE failed_at < NOW() - INTERVAL 30 DAY;

-- Limpia sesiones muertas de más de 7 días
DELETE FROM sessions WHERE last_activity < UNIX_TIMESTAMP(NOW() - INTERVAL 7 DAY);

-- Limpia caché expirada
DELETE FROM cache WHERE expiration < UNIX_TIMESTAMP();

-- Recompacta las tablas con más movimiento
OPTIMIZE TABLE meta_posts, meta_page_user, sessions, cache, jobs, failed_jobs;
