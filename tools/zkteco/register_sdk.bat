@echo off
rem G-SMaRT HRIS - register the ZKTeco SDK (zkemkeeper.dll) on this Windows PC or server.
rem Put every DLL of the ZKTeco Standalone SDK in the "sdk" folder next to this file first
rem (zkemkeeper.dll alone is not enough: it needs zkemsdk.dll and the communication DLLs).
rem Right-click this file and choose "Run as administrator".
setlocal
net session >nul 2>&1
if errorlevel 1 goto :notadmin
set "SRC=%~dp0sdk"
set "DEST=%SystemRoot%\System32"
if exist "%SystemRoot%\SysWOW64\regsvr32.exe" set "DEST=%SystemRoot%\SysWOW64"
if not exist "%SRC%\zkemkeeper.dll" goto :nodll
for %%F in (zkemsdk.dll commpro.dll comms.dll rscagent.dll rscomm.dll tcpcomm.dll usbcomm.dll) do if not exist "%SRC%\%%F" echo WARNING: %%F is not in the sdk folder. Copy it from the ZKTeco SDK if the registration or the connection fails.
echo Copying the SDK files to "%DEST%" ...
copy /Y "%SRC%\*.dll" "%DEST%\" >nul
echo Registering zkemkeeper.dll ...
"%DEST%\regsvr32.exe" /s "%DEST%\zkemkeeper.dll"
if errorlevel 1 goto :failed
echo.
echo zkemkeeper.dll is registered. Test it with:  zk_agent.bat -Test
echo For 64-bit PHP on this server also import zk_64bit_php.reg by double-clicking it.
pause
exit /b 0

:notadmin
echo Please right-click register_sdk.bat and choose "Run as administrator".
pause
exit /b 1

:nodll
echo zkemkeeper.dll was not found in the sdk folder next to this file.
pause
exit /b 1

:failed
echo The registration failed. Make sure every DLL of the ZKTeco SDK is in the sdk folder, then try again.
pause
exit /b 1
