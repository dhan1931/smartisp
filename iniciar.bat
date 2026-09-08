@echo off
REM ═══════════════════════════════════════════════════════════════════
REM   TECHSTORE - Script de Inicio Rápido
REM ═══════════════════════════════════════════════════════════════════

chcp 65001 >nul
setlocal enabledelayedexpansion

title 🌐 SmartISP - Presentación Corporativa & Tienda Online

echo.
echo ═══════════════════════════════════════════════════════════════════
echo   🌐  SMARTISP - Presentación de Empresa & Tienda Online
echo ═══════════════════════════════════════════════════════════════════
echo.
echo 🚀 Abriendo la página de presentación en tu navegador...
echo    (Desde allí encontrarás el botón directo a la Tienda Online)
echo.

REM Obtener la ruta completa del archivo index.html
set "indexPath=%cd%\index.html"

REM Abrir en el navegador predeterminado
start "" "%indexPath%"

echo ✅ Página de presentación abierta en tu navegador
echo    Acceso directo a la tienda: tienda.html
echo.
timeout /t 3 /nobreak
