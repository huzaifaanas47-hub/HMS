'use strict';
// electron-builder configuration (kept out of package.json so it can run code).
// Named .cjs ON PURPOSE: on Windows a file called electron-builder.js in the project folder is picked up by
// `npx electron-builder` (PATHEXT includes .JS) and run by Windows Script Host instead of electron-builder itself.
//
// An EMPTY signing variable makes electron-builder treat it as a file path and fail ("WIN_CSC_LINK is not correct,
// cannot resolve ... not a file"). CI passes the certificate secrets as empty strings when they are not set, so
// drop every empty one here: unsigned builds then work, and signed builds work the moment the secrets exist.
for (const k of ['CSC_LINK', 'CSC_KEY_PASSWORD', 'WIN_CSC_LINK', 'WIN_CSC_KEY_PASSWORD', 'CSC_NAME',
  'APPLE_ID', 'APPLE_APP_SPECIFIC_PASSWORD', 'APPLE_TEAM_ID']) {
  if (process.env[k] !== undefined && String(process.env[k]).trim() === '') delete process.env[k];
}

module.exports = {
  "appId": "com.talha.HMS",
  "productName": "HMS",
  "files": [
    "main.js",
    "preload.js",
    "app-config.json",
    "src/**/*",
    "renderer/**/*",
    "package.json"
  ],
  "win": {
    "target": [
      {
        "target": "nsis",
        "arch": [
          "x64"
        ]
      }
    ],
    "icon": "build/icon.ico"
  },
  "nsis": {
    "oneClick": false,
    "allowToChangeInstallationDirectory": true,
    "createDesktopShortcut": true,
    "createStartMenuShortcut": true,
    "shortcutName": "HMS",
    "include": "build/installer.nsh"
  },
  "artifactName": "HMS-Setup-${version}.${ext}",
  "mac": {
    "category": "public.app-category.medical",
    "icon": "build/icon.png",
    "target": [
      {
        "target": "dmg",
        "arch": [
          "x64",
          "arm64"
        ]
      },
      {
        "target": "zip",
        "arch": [
          "x64",
          "arm64"
        ]
      }
    ],
    "artifactName": "HMS-${version}-mac-${arch}.${ext}",
    "extendInfo": {
      "NSCameraUsageDescription": "HMS pages use the camera, for example to scan or take photos.",
      "NSMicrophoneUsageDescription": "HMS pages use the microphone, for example for calls and voice notes."
    },
    "hardenedRuntime": true
  },
  "dmg": {
    "title": "HMS ${version}${arch}"
  },
  "publish": [
    {
      "provider": "github",
      "owner": "huzaifaanas47-hub",
      "repo": "HMS",
      "releaseType": "release"
    }
  ],
  "linux": {
    "category": "Office",
    "icon": "build/icon.png",
    "maintainer": "Dr.Talha <huzaifaanas47@users.noreply.github.com>",
    "synopsis": "HMS desktop client",
    "target": [
      {
        "target": "AppImage",
        "arch": [
          "x64",
          "arm64"
        ]
      },
      {
        "target": "deb",
        "arch": [
          "x64",
          "arm64"
        ]
      }
    ],
    "artifactName": "HMS-${version}-linux-${arch}.${ext}"
  }
};
