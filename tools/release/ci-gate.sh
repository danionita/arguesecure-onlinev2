#!/usr/bin/env bash
# Decides whether a commit may be released: its newest CI run must be
# finished and green. Reads the JSON of
#   GET /repos/{owner}/{repo}/actions/workflows/ci.yml/runs?head_sha=<sha>
# on stdin; the commit is the first argument. Exit 0 = release, 1 = refuse.
# release.yml calls this before it tags; tests/Unit/Release/CiGateTest.php
# holds it to the four cases (no run, running, failed, green).
set -euo pipefail

sha="${1:?usage: ci-gate.sh <commit-sha> < runs.json}"

# Only push runs count (a pull_request run of the same commit proves nothing
# about main). Newest run of this commit by run number, then attempt, so a re-run that
# went green replaces the failed run it repeated, and a newer failure wins.
run=$(jq -c --arg sha "$sha" '
  [.workflow_runs[]? | select(.head_sha == $sha and .event == "push")]
  | sort_by([.run_number, .run_attempt]) | last // empty' )

if [ -z "$run" ]; then
  echo "No CI run found for $sha. Push the commit and wait for CI before releasing." >&2
  exit 1
fi

status=$(jq -r '.status' <<<"$run")
conclusion=$(jq -r '.conclusion // "none"' <<<"$run")
url=$(jq -r '.html_url' <<<"$run")

if [ "$status" != completed ]; then
  echo "CI is still $status for $sha ($url). Wait for it to finish, then run Release again." >&2
  exit 1
fi
if [ "$conclusion" != success ]; then
  echo "CI concluded '$conclusion' for $sha ($url). A release needs a green run." >&2
  exit 1
fi
echo "CI is green for $sha ($url)."
