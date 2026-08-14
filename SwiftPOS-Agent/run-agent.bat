@echo off
:: 设置窗口标题
title SwiftPOS Thermal Print Agent

echo ======================================================
echo           SwiftPOS Thermal Print Agent
echo ======================================================
echo.
echo  [*] Status: Connecting to local port 9100...
echo  [*] Info: Please keep this window open during checkout.
echo  [*] Note: Close this window to stop the print service.
echo.
echo ------------------------------------------------------

:: 切换到你项目代理的实际目录（/d 可以跨盘符切换）
cd /d "C:\xampp\htdocs\2-WORK\SwiftPOS Commerce\SwiftPOS-Agent"

:: 检查 Node.js 环境是否存在
where node >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Node.js is not installed or not in PATH!
    echo Please install Node.js from https://nodejs.org
    echo.
    pause
    exit
)

:: 启动打印代理
node print-agent.js

:: 预防性暂停（如果程序异常退出，可以保留报错信息不闪退）
echo.
echo [WARN] Print Agent stopped unexpectedly.
pause