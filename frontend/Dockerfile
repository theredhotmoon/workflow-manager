# ─── Stage 1: Build Vue app ───────────────────────────────────────────────────
FROM node:20-alpine AS builder

WORKDIR /app

# Install dependencies first (better layer caching)
COPY package*.json ./
RUN npm ci

# Copy source and build using the Docker-specific Vite config
# (outputs to /app/dist instead of ../backend/public)
COPY . .
RUN npm run build:docker


# ─── Stage 2: Nginx serving SPA + proxying API ───────────────────────────────
FROM nginx:1.25-alpine

# Remove default config
RUN rm /etc/nginx/conf.d/default.conf

COPY --from=builder /app/dist        /usr/share/nginx/html
COPY docker/nginx.conf               /etc/nginx/conf.d/default.conf
COPY docker/security-headers.conf    /etc/nginx/security-headers.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
