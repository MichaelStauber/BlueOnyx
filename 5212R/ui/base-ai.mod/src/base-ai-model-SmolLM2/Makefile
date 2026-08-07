ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

include $(CCETOPDIR)/devel/defines.mk

MODEL_FILE = SmolLM2-360M-Instruct-Q4_K_M.gguf
MODEL_URL = https://devel.blueonyx.it/pub/BlueOnyx/.base-ai-local-LLm/$(MODEL_FILE)

.PHONY: base-ai-model-SmolLM2-rpm fetch-model

all: base-ai-model-SmolLM2-rpm

install: base-ai-model-SmolLM2-rpm

rpm: base-ai-model-SmolLM2-rpm

fetch-model:
	@if [ -f "$(MODEL_FILE)" ]; then \
		echo "$(MODEL_FILE) already present."; \
	elif command -v curl >/dev/null 2>&1; then \
		echo "Downloading $(MODEL_FILE)..."; \
		curl -L --fail -o "$(MODEL_FILE)" "$(MODEL_URL)"; \
	elif command -v wget >/dev/null 2>&1; then \
		echo "Downloading $(MODEL_FILE)..."; \
		wget -O "$(MODEL_FILE)" "$(MODEL_URL)"; \
	else \
		echo "Neither curl nor wget is available to download $(MODEL_FILE)." >&2; \
		exit 1; \
	fi

base-ai-model-SmolLM2-rpm: fetch-model
	tar --exclude='.svn' -zcvf /tmp/base-ai-model-SmolLM2.tar.gz ../base-ai-model-SmolLM2 &> /dev/null
	$(RPMBUILD) -ta /tmp/base-ai-model-SmolLM2.tar.gz &> /tmp/rpm.log
	/bin/rm -f /tmp/base-ai-model-SmolLM2.tar.gz
