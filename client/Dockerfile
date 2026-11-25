# syntax=docker/dockerfile:1

# ---- 1. Build the SPA --------------------------------------------------------
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Vite inlines these at build time.
ARG VITE_API_URL=http://localhost:8800/api
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_APP_KEY=boardingfinder-local-key
ENV VITE_API_URL=$VITE_API_URL VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY
RUN npm run build

# ---- 2. Serve it -------------------------------------------------------------
FROM nginx:1.27-alpine
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
