#!/bin/bash
# Post-edit PHP syntax check
# Reads hook JSON from stdin, extracts file path, runs php -l

INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty')

if [[ -z "$FILE_PATH" || "$FILE_PATH" != *.php ]]; then
  exit 0
fi

if [[ ! -f "$FILE_PATH" ]]; then
  exit 0
fi

OUTPUT=$(php -l "$FILE_PATH" 2>&1)
if [[ $? -ne 0 ]]; then
  echo "$OUTPUT" >&2
  exit 2
fi
