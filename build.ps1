# Gera o .zip do plugin para anexar ao Release do GitHub (Windows).
# Uso:  powershell -ExecutionPolicy Bypass -File build.ps1
# Saida: build\maazap-vX.Y.Z.zip  (contendo a pasta maazap/)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$linha = Select-String -Path 'maazap.php' -Pattern '^\s\*\s*Version:\s*(.+)$' | Select-Object -First 1
if (-not $linha) { throw 'Nao consegui ler a versao do maazap.php' }
$versao = $linha.Matches[0].Groups[1].Value.Trim()

$dest = Join-Path $PSScriptRoot 'build'
$zip  = Join-Path $dest "maazap-v$versao.zip"
if (-not (Test-Path $dest)) { New-Item -ItemType Directory -Path $dest | Out-Null }
if (Test-Path $zip) { Remove-Item $zip -Force }

# Arquivos que vao para producao. A chave e o caminho DENTRO do zip,
# sempre com barra normal (/), que e o exigido pelo padrao ZIP.
$arquivos = [ordered]@{
    'maazap/maazap.php' = Join-Path $PSScriptRoot 'maazap.php'
    'maazap/readme.txt' = Join-Path $PSScriptRoot 'readme.txt'
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$arquivo = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
try {
    foreach ($entrada in $arquivos.Keys) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($arquivo, $arquivos[$entrada], $entrada) | Out-Null
    }
} finally {
    $arquivo.Dispose()
}

Write-Output "Pronto: $zip"
Write-Output ''
Write-Output 'Agora no GitHub:'
Write-Output "  1. Releases > Draft a new release"
Write-Output "  2. Tag: v$versao"
Write-Output "  3. Anexe o arquivo build\maazap-v$versao.zip"
Write-Output '  4. Publish release'
