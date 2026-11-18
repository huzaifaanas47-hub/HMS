; HMS installer extras (electron-builder NSIS include).
; There is no server-address page any more: the site address comes from the clinic the user joins with their
; access code (first launch). Silent installs can still pin an address with /URL=https://your-site.com ;
; it is written to install-url.txt next to HMS.exe, which the app reads on first launch.

!macro customInstall
  ${GetParameters} $0
  ClearErrors
  ${GetOptions} $0 "/URL=" $1
  ${IfNot} ${Errors}
  ${AndIf} $1 != ""
    FileOpen $2 "$INSTDIR\install-url.txt" w
    FileWrite $2 "$1"
    FileClose $2
  ${EndIf}
!macroend
