#!/bin/bash

# Quick Fix Script für Caddy Probleme
# Verwendung: ./fix-caddy.sh

echo "🔧 Caddy Quick Fix Script"
echo "========================="
echo ""

# Check if docker-compose is running
if ! docker-compose ps | grep -q caddy; then
    echo "❌ Caddy Container läuft nicht!"
    echo "Starte Container..."
    docker-compose up -d caddy
    sleep 3
fi

echo "1️⃣ Prüfe Caddy Logs..."
if docker-compose logs --tail=20 caddy | grep -q "error\|Error\|ERROR"; then
    echo "⚠️  Fehler in Caddy Logs gefunden!"
    echo ""
    echo "Letzte Fehler:"
    docker-compose logs --tail=20 caddy | grep -i error
    echo ""
    
    # Check for specific error
    if docker-compose logs caddy | grep -q "split_path"; then
        echo "✅ Erkannt: split_path Fehler"
        echo "Verwende minimale Caddyfile..."
        
        # Backup current Caddyfile
        if [ -f docker/Caddyfile ]; then
            cp docker/Caddyfile docker/Caddyfile.backup
            echo "   Backup erstellt: docker/Caddyfile.backup"
        fi
        
        # Use minimal Caddyfile
        if [ -f docker/Caddyfile.minimal ]; then
            cp docker/Caddyfile.minimal docker/Caddyfile
            echo "   Minimale Caddyfile kopiert"
        else
            echo "   Erstelle minimale Caddyfile..."
            cat > docker/Caddyfile << 'EOF'
:80

root * /var/www/html/public
php_fastcgi php:9000
file_server
EOF
        fi
        
        echo "   Starte Caddy neu..."
        docker-compose restart caddy
        sleep 3
        
        echo ""
        echo "✅ Fix angewendet!"
    fi
else
    echo "✅ Keine Fehler in Logs gefunden"
fi

echo ""
echo "2️⃣ Teste Verbindung zu PHP-FPM..."
if docker-compose exec caddy nc -z php 9000 2>/dev/null; then
    echo "✅ PHP-FPM erreichbar"
else
    echo "❌ PHP-FPM nicht erreichbar!"
    echo "   Starte PHP neu..."
    docker-compose restart php
    sleep 3
fi

echo ""
echo "3️⃣ Teste API Endpoint..."
if curl -s http://localhost:8080/health > /dev/null 2>&1; then
    echo "✅ API antwortet!"
    echo ""
    echo "Response:"
    curl -s http://localhost:8080/health | head -5
else
    echo "❌ API antwortet nicht!"
    echo ""
    echo "Prüfe Container Status:"
    docker-compose ps
fi

echo ""
echo "4️⃣ Zusammenfassung"
echo "=================="
docker-compose ps | grep -E "caddy|php"

echo ""
echo "📊 Caddy Status:"
if docker-compose ps caddy | grep -q "Up"; then
    echo "   ✅ Container läuft"
else
    echo "   ❌ Container läuft nicht!"
fi

echo ""
echo "🔍 Für mehr Details:"
echo "   - Logs: docker-compose logs caddy"
echo "   - Config: docker-compose exec caddy cat /etc/caddy/Caddyfile"
echo "   - Test: curl -v http://localhost:8080/health"
echo ""
echo "📚 Siehe: DOCKER.md (Abschnitt Troubleshooting)"
