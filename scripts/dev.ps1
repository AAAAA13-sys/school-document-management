$PSScriptRootCurrent = $PSScriptRoot
& (Join-Path $PSScriptRootCurrent 'kickstart.ps1') @args

