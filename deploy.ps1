#requires -Version 5.1
$ErrorActionPreference = "Stop"

Write-Host "=== SmartCRMS Auto Deploy (Local) ==="

# (1) go to project root (folder script berada)
Set-Location -Path $PSScriptRoot

# (2) ensure tools exist
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
  throw "git tidak ditemukan di PATH."
}
if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
  throw "npm tidak ditemukan di PATH."
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
  throw "php tidak ditemukan di PATH."
}

# (3) ensure branch main
$branch = (git rev-parse --abbrev-ref HEAD).Trim()
if ($branch -ne "main") {
  throw "Kamu sedang di branch '$branch'. Pindah dulu: git checkout main"
}

# (4) check changes
$status = git status --porcelain
if ([string]::IsNullOrWhiteSpace($status)) {
  Write-Host "[INFO] Tidak ada perubahan. Selesai."
  exit 0
}

# (5) build vite
Write-Host "[STEP] npm run build ..."
npm run build

# (6) clear laravel cache (local)
Write-Host "[STEP] php artisan optimize:clear ..."
php artisan optimize:clear | Out-Null

# (7) stage build + all changes
Write-Host "[STEP] git add public/build ..."
git add public/build

Write-Host "[STEP] git add . ..."
git add .

# (8) commit message (ambil argumen)
$msg = $args -join " "
if ([string]::IsNullOrWhiteSpace($msg)) { $msg = "update: auto deploy" }

Write-Host "[STEP] git commit -m '$msg' ..."
git commit -m "$msg"

# (9) push
Write-Host "[STEP] git push origin main ..."
git push origin main

Write-Host "[OK] Deploy lokal selesai. Lanjut di hosting: git pull origin main"
