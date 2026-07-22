#!/usr/bin/env bash
# Gera o .zip do plugin para anexar ao Release do GitHub.
# Uso:  ./build.sh
# Saída: build/maazap-vX.Y.Z.zip  (contendo a pasta maazap/)

set -e
cd "$(dirname "$0")"

VERSAO=$(grep -m1 "^ \* Version:" maazap.php | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')
if [ -z "$VERSAO" ]; then
  echo "Nao consegui ler a versao do maazap.php"; exit 1
fi

DEST="build"
PASTA="$DEST/maazap"
ZIP="$DEST/maazap-v$VERSAO.zip"

rm -rf "$PASTA" "$ZIP"
mkdir -p "$PASTA"

# só o que o plugin precisa em produção
cp maazap.php readme.txt "$PASTA/"

# usa o "zip" quando existe; no Windows cai no compactador nativo do PowerShell
if command -v zip >/dev/null 2>&1; then
  ( cd "$DEST" && zip -qr "maazap-v$VERSAO.zip" maazap )
else
  powershell -NoProfile -Command "Compress-Archive -Path '$PASTA' -DestinationPath '$ZIP' -Force"
fi
rm -rf "$PASTA"

echo "Pronto: $ZIP"
echo
echo "Agora no GitHub:"
echo "  1. Releases > Draft a new release"
echo "  2. Tag: v$VERSAO"
echo "  3. Anexe o arquivo $ZIP"
echo "  4. Publish release"
