# The assistant runtime (W50) with its launcher and model catalogue baked in
# (W101, D63), so a deployment runs it from the published image alone.
#
# The base is pinned by tag and digest (D56) and is the whole of llama.cpp
# here: this adds two files and changes no binary, flag or library, so the
# 2.6 measurements in docs/spikes/2.6-models.md still describe what runs.
# tools/spike/assistant/verify.sh reads the FROM line below to benchmark the
# same build.
FROM ghcr.io/ggml-org/llama.cpp:server-b11176@sha256:6257697a7f5d034b8fb499ddb07af3e250506352f94102054252a23f3b85e0af

COPY assistant.sh models.conf /opt/arguesecure/
