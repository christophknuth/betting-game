# Node 24 is the active LTS line. Node 18 went end-of-life in April 2025 and
# stopped receiving security fixes, which is not a base image to ship.
FROM node:24-alpine AS builder

WORKDIR /app

# Copy package files
COPY package*.json ./

# `npm ci`, not `npm install`: it installs exactly what package-lock.json
# pins, so the image cannot quietly pick up a different dependency tree than
# the one CI tested.
RUN npm ci

# Copy source files
COPY . .

# Build for production
RUN npm run build

# Production stage with nginx
FROM nginx:alpine

# Copy built assets from builder
COPY --from=builder /app/dist /usr/share/nginx/html

# Copy nginx configuration
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Expose port 80
EXPOSE 80

# Start nginx
CMD ["nginx", "-g", "daemon off;"]
