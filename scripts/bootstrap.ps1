# Checks that the tools needed for Trik development are installed (Windows PowerShell).
$tools = 'docker','php','composer','node','pnpm','go','flutter'
$missing = 0
Write-Host "Trik · checking tools"
foreach ($t in $tools) {
  $cmd = Get-Command $t -ErrorAction SilentlyContinue
  if ($cmd) { Write-Host ("  OK  {0,-10} {1}" -f $t, $cmd.Source) -ForegroundColor Green }
  else { Write-Host ("  --  {0,-10} not found" -f $t) -ForegroundColor Red; $missing++ }
}
if (-not (Test-Path .env)) { Copy-Item .env.example .env; Write-Host "  created .env from .env.example" }
if ($missing -gt 0) { exit 1 }
