<#
.SYNOPSIS
    Hybrid dev launcher: db + backend in Docker, frontend running locally via Vite.

.DESCRIPTION
    Starts the db and backend services from docker-compose.yml, layered with
    docker-compose.dev.yml (exposes the backend on localhost:8090, or BACKEND_PORT, and relaxes a
    few env vars for local development). Then runs the Vite dev server in
    ./frontend, which proxies /api and /sanctum to the containerized backend
    (see vite.config.hybrid.js).

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

    # The backend's host port: BACKEND_PORT from the environment, else from .env (as compose reads
    # it), else 8090. Vite's proxy is pointed at the same port.
    $backendPort = $env:BACKEND_PORT
    if (-not $backendPort) {
        $line = Select-String -Path '.env' -Pattern '^\s*BACKEND_PORT\s*=\s*(\d+)' | Select-Object -First 1
        $backendPort = if ($line) { $line.Matches[0].Groups[1].Value } else { '8090' }
    }
    $backendUrl = "http://localhost:$backendPort"
    if (-not $env:BACKEND_URL) { $env:BACKEND_URL = $backendUrl }

    # /api/health answers 200 once PHP is up, migrations have run and the database accepts a query
    # (503 before that). Migrations run on start, so allow up to two minutes. Probed on 127.0.0.1:
    # the port is bound to IPv4 loopback only, and "localhost" tries ::1 first, which can outlast
    # the 2-second timeout.
    $healthUrl = "http://127.0.0.1:$backendPort/api/health"
    Write-Host "==> Waiting for the backend to be ready on $healthUrl ..." -ForegroundColor Cyan
    $ready = $false
    for ($i = 0; $i -lt 60; $i++) {
        try {
            $response = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200) {
                $ready = $true
                break
            }
        } catch {
            Start-Sleep -Seconds 2
        }
    }

    if (-not $ready) {
        Write-Warning "Backend wasn't ready on /api/health within 2 minutes - check 'docker compose logs -f backend'. Continuing anyway."
    } else {
        Write-Host "==> Backend is ready." -ForegroundColor Green
    }
}
finally {
    Pop-Location
}

Write-Host "==> Starting frontend dev server ..." -ForegroundColor Cyan
Push-Location (Join-Path $root 'frontend')
try {
    npm run dev:hybrid
}
finally {
    Pop-Location
}
