# Emisor de editus: videos pregrabados que salen en vivo a una hora programada (Node + ffmpeg).
FROM node:22-bookworm-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg fonts-dejavu-core ca-certificates \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY servidor.mjs prueba.mjs ./
ENV EMISOR_DIR=/datos EMISOR_HOST=127.0.0.1 EMISOR_PUERTO=7890 NODE_ENV=production
USER node
CMD ["node", "servidor.mjs"]
