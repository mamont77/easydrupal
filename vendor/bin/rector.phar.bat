@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../rector/rector-prefixed/rector.phar
php "%BIN_TARGET%" %*
