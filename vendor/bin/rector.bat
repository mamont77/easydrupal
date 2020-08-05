@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../rector/rector-prefixed/rector
php "%BIN_TARGET%" %*
