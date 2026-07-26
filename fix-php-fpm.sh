#!/bin/bash

# Quick Fix Script für PHP-FPM Probleme
# Verwendung: ./fix-php-fpm.sh

echo "🔧 PHP-FPM Quick Fix Script"
echo "==========================="
echo ""

# Check if docker-compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "❌ docker-compose nicht gefunden!"
    exit 1
fi

echo "1️⃣ Prüfe PHP-FPM Container Status..."
if docker-compose ps php | grep -q "Up"; then
    echo "✅ Container läuft"
else
    echo "⚠️  Container läuft nicht!"
    echo "   Versuche zu starten..."
    docker-compose up -d php
    sleep 3
fi

echo ""
echo "2️⃣ Prüfe PHP-FPM Logs..."
if docker-compose logs --tail=20 php | grep -q "error\|Error\|ERROR\|unknown entry"; then
    echo "⚠️  Fehler in PHP-FPM Logs gefunden!"
    echo ""
    echo "Letzte Fehler:"
    docker-compose logs --tail=20 php | grep -i "error\|unknown"
    echo ""
    
    # Check for specific errors
    if docker-compose logs php | grep -q "unknown entry"; then
        echo "✅ Erkannt: 'unknown entry' Fehler in Config"
        echo "   Verwende minimale php-fpm.conf..."
        
        # Backup current config
        if [ -f docker/php-fpm.conf ]; then
            cp docker/php-fpm.conf docker/php-fpm.conf.backup
            echo "   Backup erstellt: docker/php-fpm.conf.backup"
        fi
        
        # Use minimal config
        if [ -f docker/php-fpm.conf.minimal ]; then
            cp docker/php-fpm.conf.minimal docker/php-fpm.conf
            echo "   Minimale php-fpm.conf kopiert"
        else
            echo "   Erstelle minimale php-fpm.conf..."
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
        
        echo "   Baue PHP Container neu..."
        docker-compose build --no-cache php
        
        echo "   Starte PHP neu..."
        docker-compose up -d php
        sleep 5
        
        echo ""
        echo "✅ Fix angewendet!"
    fi
else
    echo "✅ Keine Fehler in Logs gefunden"
fi

echo ""
echo "3️⃣ Teste PHP-FPM Konfiguration..."
if docker-compose exec php php-fpm -t 2>&1 | grep -q "successful"; then
    echo "✅ PHP-FPM Config ist valide"
else
    echo "❌ PHP-FPM Config hat Fehler!"
    docker-compose exec php php-fpm -t 2>&1
fi

echo ""
echo "4️⃣ Teste PHP-FPM Erreichbarkeit..."
if docker-compose exec caddy nc -z php 9000 2>/dev/null; then
    echo "✅ PHP-FPM von Caddy erreichbar"
else
    echo "⚠️  PHP-FPM nicht von Caddy erreichbar!"
    echo "   Prüfe Netzwerk..."
    docker network inspect betting-game-network | grep -A 5 "betting-game-php\|betting-game-caddy"
fi

echo ""
echo "5️⃣ Teste API Endpoint..."
if curl -s http://localhost:8080/health > /dev/null 2>&1; then
    echo "✅ API antwortet!"
    echo ""
    echo "Response:"
    curl -s http://localhost:8080/health | head -5
else
    echo "❌ API antwortet nicht!"
    echo ""
    echo "Mögliche Ursachen:"
    echo "   - PHP-FPM läuft nicht"
    echo "   - Caddy läuft nicht"
    echo "   - Netzwerk Problem"
fi

echo ""
echo "6️⃣ Zusammenfassung"
echo "=================="
echo ""
echo "Container Status:"
docker-compose ps | grep -E "php|caddy"

echo ""
echo "PHP-FPM Status:"
if docker-compose ps php | grep -q "Up"; then
    echo "   ✅ Container läuft"
    
    # Check if PHP-FPM process is running
    if docker-compose exec php pgrep php-fpm > /dev/null 2>&1; then
        echo "   ✅ PHP-FPM Prozess aktiv"
    else
        echo "   ❌ PHP-FPM Prozess nicht aktiv!"
    fi
else
    echo "   ❌ Container läuft nicht!"
fi

echo ""
echo "🔍 Für mehr Details:"
echo "   - Logs:       docker-compose logs php"
echo "   - Config:     docker-compose exec php php-fpm -t"
echo "   - Extensions: docker-compose exec php php -m"
echo "   - Version:    docker-compose exec php php -v"
echo ""
echo "📚 Siehe: DOCKER.md (Abschnitt Troubleshooting)"
echo ""

# Return appropriate exit code
if docker-compose ps php | grep -q "Up" && curl -s http://localhost:8080/health > /dev/null 2>&1; then
    echo "✅ Alles funktioniert!"
    exit 0
else
    echo "⚠️  Es gibt noch Probleme. Siehe oben für Details."
    exit 1
fi
