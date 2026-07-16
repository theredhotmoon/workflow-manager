<#
.SYNOPSIS
    Hybrid dev launcher: db + backend in Docker, frontend running locally via Vite.

.DESCRIPTION
    Starts the db and backend services from docker-compose.yml, layered with
    docker-compose.dev.yml (exposes the backend on localhost:8090 and relaxes a
    few env vars for local development). Then runs the Vite dev server in
    ./frontend, which proxies /api and /sanctum to the containerized backend
    (see vite.config.js).

    Full-Docker mode (all three services, including frontend) is unaffected -
    that's still just: docker compose up -d --build
#>

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

Push-Location $root
try {
    if (-not (Test-Path '.env')) {
        Write-Error "Missing .env - copy .env.example to .env and fill in APP_KEY / DB values first."
        exit 1
    }

    Write-Host "==> Starting db + backend containers..." -ForegroundColor Cyan
    docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build db backend

    Write-Host "==> Waiting for backend to become healthy on http://localhost:8090 ..." -ForegroundColor Cyan
    $ready = $false
    for ($i = 0; $i -lt 30; $i++) {
        try {
            $response = Invoke-WebRequest -Uri 'http://localhost:8090/healthz' -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200) {
                $ready = $true
                break
            }
        } catch {
            Start-Sleep -Seconds 1
        }
    }

    if (-not $ready) {
        Write-Warning "Backend didn't respond on /healthz within 30s - check 'docker compose logs -f backend'. Continuing anyway."
    } else {
        Write-Host "==> Backend is up." -ForegroundColor Green
    }
}
finally {
    Pop-Location
}

Write-Host "==> Starting frontend dev server ..." -ForegroundColor Cyan
Push-Location (Join-Path $root 'frontend')
try {
    npm run dev
}
finally {
    Pop-Location
}
