#!/bin/bash
# Block edits to vendored KD2 framework files
# These should be modified upstream in the kd2fw repo

INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty')

if [[ "$FILE_PATH" == */lib/KD2/* ]]; then
  jq -n '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: "KD2 files are vendored from the kd2fw repo — edit upstream instead"
    }
  }'
fi
