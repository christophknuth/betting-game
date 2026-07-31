#!/bin/bash

# Quick fix script for Caddy problems
# Usage: ./fix-caddy.sh

echo "🔧 Caddy Quick Fix Script"
echo "========================="
echo ""

# Check if docker-compose is running
if ! docker-compose ps | grep -q caddy; then
    echo "❌ Caddy container is not running!"
    echo "Starting container..."
    docker-compose up -d caddy
    sleep 3
fi

echo "1️⃣ Checking Caddy logs..."
if docker-compose logs --tail=20 caddy | grep -q "error\|Error\|ERROR"; then
    echo "⚠️  Found errors in the Caddy logs!"
    echo ""
    echo "Most recent errors:"
    docker-compose logs --tail=20 caddy | grep -i error
    echo ""

    # Check for specific error
    if docker-compose logs caddy | grep -q "split_path"; then
        echo "✅ Identified: split_path error"
        echo "Falling back to the minimal Caddyfile..."

        # Backup current Caddyfile
        if [ -f docker/Caddyfile ]; then
            cp docker/Caddyfile docker/Caddyfile.backup
            echo "   Backup created: docker/Caddyfile.backup"
        fi

        # Use minimal Caddyfile
        if [ -f docker/Caddyfile.minimal ]; then
            cp docker/Caddyfile.minimal docker/Caddyfile
            echo "   Minimal Caddyfile copied"
        else
            echo "   Creating a minimal Caddyfile..."
            cat > docker/Caddyfile << 'EOF'
:80

root * /var/www/html/public
php_fastcgi php:9000
file_server
EOF
        fi

        echo "   Restarting Caddy..."
        docker-compose restart caddy
        sleep 3

        echo ""
        echo "✅ Fix applied!"
    fi
else
    echo "✅ No errors found in the logs"
fi

echo ""
echo "2️⃣ Testing the connection to PHP-FPM..."
if docker-compose exec caddy nc -z php 9000 2>/dev/null; then
    echo "✅ PHP-FPM reachable"
else
    echo "❌ PHP-FPM not reachable!"
    echo "   Restarting PHP..."
    docker-compose restart php
    sleep 3
fi

echo ""
echo "3️⃣ Testing the API endpoint..."
if curl -s http://localhost:8080/health > /dev/null 2>&1; then
    echo "✅ API responds!"
    echo ""
    echo "Response:"
    curl -s http://localhost:8080/health | head -5
else
    echo "❌ API does not respond!"
    echo ""
    echo "Checking container status:"
    docker-compose ps
fi

echo ""
echo "4️⃣ Summary"
echo "=========="
docker-compose ps | grep -E "caddy|php"

echo ""
echo "📊 Caddy status:"
if docker-compose ps caddy | grep -q "Up"; then
    echo "   ✅ Container is running"
else
    echo "   ❌ Container is not running!"
fi

echo ""
echo "🔍 For more detail:"
echo "   - Logs: docker-compose logs caddy"
echo "   - Config: docker-compose exec caddy cat /etc/caddy/Caddyfile"
echo "   - Test: curl -v http://localhost:8080/health"
echo ""
echo "📚 See: DOCKER.md (Troubleshooting section)"
