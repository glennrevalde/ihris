@echo off
rem G-SMaRT HRIS - ZKTeco office PC agent.
rem Starts zk_agent.ps1 with the 32-bit PowerShell (zkemkeeper.dll is a 32-bit DLL).
rem   zk_agent.bat          read the terminals and send the new logs to the HRIS
rem   zk_agent.bat -Test    connect and show what would be sent, send nothing
setlocal
set PS=%SystemRoot%\SysWOW64\WindowsPowerShell\v1.0\powershell.exe
if not exist "%PS%" set PS=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe
"%PS%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0zk_agent.ps1" %*
exit /b %ERRORLEVEL%
