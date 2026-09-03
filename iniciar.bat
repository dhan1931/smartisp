@echo off
REM ═══════════════════════════════════════════════════════════════════
REM   TECHSTORE - Script de Inicio Rápido
REM ═══════════════════════════════════════════════════════════════════

chcp 65001 >nul
setlocal enabledelayedexpansion

title 🛍️ TechStore - Abriendo en Navegador...

echo.
echo ═══════════════════════════════════════════════════════════════════
echo   🛍️  TECHSTORE - Tienda de Electrónica e Infraestructura TI
echo ═══════════════════════════════════════════════════════════════════
echo.
echo 🚀 Abriendo la tienda en tu navegador...
echo.

REM Obtener la ruta completa del archivo index.html
set "indexPath=%cd%\index.html"

REM Abrir en el navegador predeterminado
start "" "%indexPath%"

echo ✅ Tienda abierta en tu navegador
echo.
echo Colores: Azul #003366 | Celeste #00A8E8 | Blanco #FFFFFF
echo.
timeout /t 3 /nobreak
