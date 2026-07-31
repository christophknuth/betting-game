#!/bin/bash

# Quick fix script for PHP-FPM problems
# Usage: ./fix-php-fpm.sh

echo "🔧 PHP-FPM Quick Fix Script"
echo "==========================="
echo ""

# Check if docker-compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "❌ docker-compose not found!"
    exit 1
fi

echo "1️⃣ Checking PHP-FPM container status..."
if docker-compose ps php | grep -q "Up"; then
    echo "✅ Container is running"
else
    echo "⚠️  Container is not running!"
    echo "   Trying to start it..."
    docker-compose up -d php
    sleep 3
fi

echo ""
echo "2️⃣ Checking PHP-FPM logs..."
if docker-compose logs --tail=20 php | grep -q "error\|Error\|ERROR\|unknown entry"; then
    echo "⚠️  Found errors in the PHP-FPM logs!"
    echo ""
    echo "Most recent errors:"
    docker-compose logs --tail=20 php | grep -i "error\|unknown"
    echo ""

    # Check for specific errors
    if docker-compose logs php | grep -q "unknown entry"; then
        echo "✅ Identified: 'unknown entry' error in the config"
        echo "   Falling back to the minimal php-fpm.conf..."

        # Backup current config
        if [ -f docker/php-fpm.conf ]; then
            cp docker/php-fpm.conf docker/php-fpm.conf.backup
            echo "   Backup created: docker/php-fpm.conf.backup"
        fi

        # Use minimal config
        if [ -f docker/php-fpm.conf.minimal ]; then
            cp docker/php-fpm.conf.minimal docker/php-fpm.conf
            echo "   Minimal php-fpm.conf copied"
        else
            echo "   Creating a minimal php-fpm.conf..."
            cat > docker/php-fpm.conf << 'EOF'
[www]
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
listen = 9000
catch_workers_output = yes
EOF
        fi

        echo "   Rebuilding the PHP container..."
        docker-compose build --no-cache php

        echo "   Restarting PHP..."
        docker-compose up -d php
        sleep 5

        echo ""
        echo "✅ Fix applied!"
    fi
else
    echo "✅ No errors found in the logs"
fi

echo ""
echo "3️⃣ Testing the PHP-FPM configuration..."
if docker-compose exec php php-fpm -t 2>&1 | grep -q "successful"; then
    echo "✅ PHP-FPM config is valid"
else
    echo "❌ PHP-FPM config has errors!"
    docker-compose exec php php-fpm -t 2>&1
fi

echo ""
echo "4️⃣ Testing PHP-FPM reachability..."
if docker-compose exec caddy nc -z php 9000 2>/dev/null; then
    echo "✅ PHP-FPM reachable from Caddy"
else
    echo "⚠️  PHP-FPM not reachable from Caddy!"
    echo "   Checking the network..."
    docker network inspect betting-game-network | grep -A 5 "betting-game-php\|betting-game-caddy"
fi

echo ""
echo "5️⃣ Testing the API endpoint..."
if curl -s http://localhost:8080/health > /dev/null 2>&1; then
    echo "✅ API responds!"
    echo ""
    echo "Response:"
    curl -s http://localhost:8080/health | head -5
else
    echo "❌ API does not respond!"
    echo ""
    echo "Possible causes:"
    echo "   - PHP-FPM is not running"
    echo "   - Caddy is not running"
    echo "   - Network problem"
fi

echo ""
echo "6️⃣ Summary"
echo "=========="
echo ""
echo "Container status:"
docker-compose ps | grep -E "php|caddy"

echo ""
echo "PHP-FPM status:"
if docker-compose ps php | grep -q "Up"; then
    echo "   ✅ Container is running"

    # Check if PHP-FPM process is running
    if docker-compose exec php pgrep php-fpm > /dev/null 2>&1; then
        echo "   ✅ PHP-FPM process active"
    else
        echo "   ❌ PHP-FPM process not active!"
    fi
else
    echo "   ❌ Container is not running!"
fi

echo ""
echo "🔍 For more detail:"
echo "   - Logs:       docker-compose logs php"
echo "   - Config:     docker-compose exec php php-fpm -t"
echo "   - Extensions: docker-compose exec php php -m"
echo "   - Version:    docker-compose exec php php -v"
echo ""
echo "📚 See: DOCKER.md (Troubleshooting section)"
echo ""

# Return appropriate exit code
if docker-compose ps php | grep -q "Up" && curl -s http://localhost:8080/health > /dev/null 2>&1; then
    echo "✅ Everything works!"
    exit 0
else
    echo "⚠️  There are still problems. See above for details."
    exit 1
fi
