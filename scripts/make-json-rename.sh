#!/bin/bash
# scripts/make-json-rename.sh
# Usage: bash scripts/make-json-rename.sh <text-domain>

TEXT_DOMAIN="$1"
LANG_DIR="languages"

if [ -z "$TEXT_DOMAIN" ]; then
  echo "Error: TEXT_DOMAIN is required."
  echo "Usage: bash scripts/make-json-rename.sh <text-domain>"
  exit 1
fi

echo "Processing JSON files for domain: $TEXT_DOMAIN"

for json_file in "$LANG_DIR"/${TEXT_DOMAIN}-*-????????????????????????????????.json; do
  [ -f "$json_file" ] || { echo "No matching JSON files found."; exit 0; }

  # JSONの source フィールドからJSファイル名（拡張子なし）を取得
  source_handle=$(python3 -c "
import json, os, sys
try:
    data = json.load(open('$json_file', encoding='utf-8'))
    src = data.get('source', '')
    print(os.path.splitext(os.path.basename(src))[0])
except Exception as e:
    print('', end='')
")

  if [ -z "$source_handle" ]; then
    echo "  Skipped (could not read source): $(basename $json_file)"
    continue
  fi

  # ロケール部分を抽出（text-domain- を除去し、末尾の -{md5} を除去）
  basename_no_ext=$(basename "$json_file" .json)
  locale=$(echo "$basename_no_ext" \
    | sed "s/^${TEXT_DOMAIN}-//" \
    | sed 's/-[a-f0-9]\{32\}$//')

  new_name="${LANG_DIR}/${TEXT_DOMAIN}-${locale}-${source_handle}.json"

  # 既存ファイルがあれば削除してから移動
  if [ -f "$new_name" ]; then
    rm "$new_name"
    echo "  Removed old: $(basename $new_name)"
  fi

  mv "$json_file" "$new_name"
  echo "  Renamed: $(basename $json_file) → $(basename $new_name)"
done

echo "Done."