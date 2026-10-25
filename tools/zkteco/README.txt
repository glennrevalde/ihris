G-SMaRT HRIS - connecting ZKTeco biometric terminals
=====================================================

The HRIS can get the attendance logs of a ZKTeco terminal in three ways. Choose one per terminal in
Attendance (AMS) > Biometric Device Setup > Connection method.

1. Built-in ZKTeco protocol (recommended when the HRIS server is on the office network)
   Nothing to install, works on Linux and Windows servers. The HRIS talks to the terminal itself on
   UDP or TCP port 4370, the same protocol zkemkeeper.dll uses. The web server must reach the
   terminal's IP address (same network or a VPN).

2. ZKTeco SDK, zkemkeeper.dll (only when the HRIS runs on a Windows server, for example XAMPP)
   The HRIS calls zkemkeeper.dll through PHP's COM extension.
   a. Copy every DLL of the ZKTeco Standalone SDK into the "sdk" folder next to this file.
      The zkemkeeper.dll you gave (version 6.0.3.3, 32-bit) is already there, but it cannot work alone:
      it loads zkemsdk.dll, which loads the communication DLLs. The usual set of the SDK is
         zkemkeeper.dll  zkemsdk.dll  commpro.dll  comms.dll  rscagent.dll  rscomm.dll
         tcpcomm.dll  usbcomm.dll
      Take them from the folder of your programmer's HRIS (often next to its .exe) or from the
      ZKTeco "Standalone SDK" download, all from the same SDK version.
   b. Right-click register_sdk.bat > Run as administrator. It copies the DLLs to
      C:\Windows\SysWOW64 and registers zkemkeeper.dll.
   c. In php.ini enable  extension=com_dotnet  and restart Apache.
   d. XAMPP 8 runs a 64-bit PHP, which cannot load a 32-bit DLL directly: double-click
      zk_64bit_php.reg (administrator) so Windows runs zkemkeeper.dll in its own 32-bit host process.
      (Or use the 64-bit build of the ZKTeco SDK, registered with C:\Windows\System32\regsvr32.)
   e. Biometric Device Setup > Connection method: ZKTeco SDK > Test connection.

3. Office PC agent (when the HRIS is hosted on the internet, for example https://your-domain)
   A server on the internet cannot reach 192.168.x.x addresses of the office. A Windows PC of the
   office reads the terminal with zkemkeeper.dll and sends the logs to the HRIS over HTTPS.
   a. In Biometric Device Setup set Connection method: Office PC agent, save, then press
      Generate token. Download the agent package (it contains zk_agent.json with the token).
   b. On the office PC unzip it to a short folder, for example C:\zkteco
      (keep zk_agent.ps1, zk_agent.bat, zk_agent.json, register_sdk.bat and the sdk folder together).
   c. Put the ZKTeco SDK DLLs in C:\zkteco\sdk and run register_sdk.bat as administrator (step 2a/2b).
   d. Open a Command Prompt in C:\zkteco and run:   zk_agent.bat -Test
      It connects to the terminal and to the HRIS and shows how many logs would be sent.
   e. Run zk_agent.bat once to send the logs, then schedule it, for example every 15 minutes:
         schtasks /Create /TN "GSMaRT HRIS ZK agent" /SC MINUTE /MO 15 /TR "C:\zkteco\zk_agent.bat" /RU SYSTEM
   The agent only sends logs newer than the last one the HRIS received. Every run is written to
   zk_agent.log. In the HRIS the logs appear in Import Attendance Logs (or are posted at once when
   "Post the logs at once" is ticked on the device).

Communication key ("The device rejected the communication key")
   The key the HRIS sends is the terminal's Comm Key: Menu > COMM. (Communication) > Comm Key, or on
   some models Menu > System > Comm. Password. 0 means no key. It is NOT the administrator password of
   the terminal. Enter the same number in "Comm. key" of the device in the HRIS.
   (Version 10 of the HRIS corrects how the key is scrambled; terminals that ask for a key even when
   it is 0 now connect with 0.)

Other checks
   - Only one program can be connected to a terminal at a time: close the old HRIS / ZKTime while testing.
   - UDP or TCP: most terminals answer both on port 4370; if one times out, try the other.
   - Device ID (machine number) is usually 1.
