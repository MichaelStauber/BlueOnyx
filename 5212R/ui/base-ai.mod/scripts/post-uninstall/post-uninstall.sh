#!/bin/sh

set -u

echo "post-uninstall $$ $0" >> /tmp/install.log

rpms="
base-ai-model-SmolLM2
base-ai-llama
base-ai-knowledge
base-ai-core
base-ai-ui
base-ai-locale-pt_PT
base-ai-locale-nl_NL
base-ai-locale-ja_JP
base-ai-locale-it_IT
base-ai-locale-fr_FR
base-ai-locale-es_ES
base-ai-locale-en_US
base-ai-locale-de_DE
base-ai-locale-da_DK
base-ai-glue
base-ai-capstone
"

for rpm_name in $rpms; do
    rpm -e --quiet --nodeps "$rpm_name" >/dev/null 2>&1 || :
done

exit 0
