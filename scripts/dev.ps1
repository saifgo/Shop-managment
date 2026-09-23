$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Host "Created .env from .env.example"
}

docker compose up -d --build

Write-Host "Waiting for API health..."
for ($i = 1; $i -le 30; $i++) {
    try {
        $response = Invoke-WebRequest -Uri "http://localhost:8080/api/health" -UseBasicParsing -TimeoutSec 2
        if ($response.StatusCode -eq 200) {
            Write-Host "API is healthy"
            break
        }
    } catch {
        Start-Sleep -Seconds 2
    }
}

try {
    docker compose exec api php bin/console doctrine:migrations:migrate --no-interaction
} catch {
    Write-Host "Migration skipped (API may still be starting)"
}

Write-Host ""
Write-Host "Tittawin Management System is running:"
Write-Host "  API:      http://localhost:8080/api/health"
Write-Host "  OpenAPI:  http://localhost:8080/api/doc"
Write-Host "  Frontend: http://localhost:5173"
Write-Host "  Mailpit:  http://localhost:8025"
Write-Host "  MinIO:    http://localhost:9001"
