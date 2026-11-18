; HMS installer extras (electron-builder NSIS include).
; Adds a "HMS Server Address" page after the install-folder page and writes the answer to
; install-url.txt next to HMS.exe. The app reads it on first launch. Silent installs can pass
; /URL=https://your-site.com ; otherwise the default below is used.
; (The page functions live inside the macro because this file is included before MUI2.nsh.)

!define HMS_DEFAULT_URL "https://medicglobe.fun"

!ifndef BUILD_UNINSTALLER
  !include nsDialogs.nsh
  !include LogicLib.nsh
  Var HMS_UrlInput
  Var HMS_Url
!endif

!macro customPageAfterChangeDir
  !ifndef BUILD_UNINSTALLER
    Function hmsUrlPageCreate
      !insertmacro MUI_HEADER_TEXT "HMS Server Address" "Enter the web address of your HMS site."
      nsDialogs::Create 1018
      Pop $0
      ${If} $0 == error
        Abort
      ${EndIf}

      StrCpy $HMS_Url "${HMS_DEFAULT_URL}"
      ; re-installing: offer the address that was used last time
      ClearErrors
      FileOpen $1 "$INSTDIR\install-url.txt" r
      ${IfNot} ${Errors}
        FileRead $1 $2
        FileClose $1
        ${If} $2 != ""
          StrCpy $HMS_Url $2
        ${EndIf}
      ${EndIf}

      ${NSD_CreateLabel} 0 0 100% 24u "This is the website HMS will open in its tabs (for example https://hospital.example.com). It can be changed later by your administrator."
      Pop $0
      ${NSD_CreateText} 0 34u 100% 12u "$HMS_Url"
      Pop $HMS_UrlInput
      nsDialogs::Show
    FunctionEnd

    Function hmsUrlPageLeave
      ${NSD_GetText} $HMS_UrlInput $HMS_Url
      StrCpy $0 $HMS_Url 7
      StrCpy $1 $HMS_Url 8
      ${If} $0 != "http://"
      ${AndIf} $1 != "https://"
        MessageBox MB_ICONEXCLAMATION|MB_OK "Please enter the full address, starting with http:// or https://"
        Abort
      ${EndIf}
    FunctionEnd

    Page custom hmsUrlPageCreate hmsUrlPageLeave
  !endif
!macroend

!macro customInstall
  ${If} $HMS_Url == ""
    ; silent install, or the page was skipped: /URL=... or the built-in default
    ${GetParameters} $0
    ${GetOptions} $0 "/URL=" $HMS_Url
  ${EndIf}
  ${If} $HMS_Url == ""
    StrCpy $HMS_Url "${HMS_DEFAULT_URL}"
  ${EndIf}
  FileOpen $0 "$INSTDIR\install-url.txt" w
  FileWrite $0 "$HMS_Url"
  FileClose $0
!macroend
