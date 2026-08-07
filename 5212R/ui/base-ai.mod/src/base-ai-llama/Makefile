ifndef CCETOPDIR
CCETOPDIR = /usr/sausalito
endif

include $(CCETOPDIR)/devel/defines.mk

# llama.cpp Version (bXXXX releases)
LLAMA_VERSION = b9873
LLAMA_TAR = llama.cpp-$(LLAMA_VERSION).tar.gz
LLAMA_DIR = llama.cpp-$(LLAMA_VERSION)
LLAMA_PATCH_STAMP = $(LLAMA_DIR)/.blueonyx-patched
JOBS ?= 4
EL_MAJOR ?= $(shell value=`rpm -E '%{?rhel}' 2>/dev/null`; if [ -n "$$value" ]; then echo "$$value"; else . /etc/os-release && echo "$${VERSION_ID%%.*}"; fi)
LLAMA_TOOLSET_ROOT ?= $(if $(filter 10 11 12,$(EL_MAJOR)),/usr,/opt/rh/gcc-toolset-14/root/usr)
LLAMA_TOOLSET_CC ?= $(LLAMA_TOOLSET_ROOT)/bin/gcc
LLAMA_TOOLSET_CXX ?= $(LLAMA_TOOLSET_ROOT)/bin/g++
LLAMA_CMAKE_FLAGS = \
	-DLLAMA_BUILD_TESTS=OFF \
	-DLLAMA_BUILD_EXAMPLES=OFF \
	-DLLAMA_BUILD_UI=OFF \
	-DLLAMA_BUILD_WEBUI=OFF \
	-DLLAMA_USE_PREBUILT_UI=OFF \
	-DLLAMA_USE_PREBUILT_WEBUI=OFF \
	-DLLAMA_BUILD_TOOLS=ON \
	-DLLAMA_BUILD_SERVER=ON \
	-DGGML_NATIVE=OFF \
	-DGGML_BACKEND_DL=ON \
	-DGGML_VULKAN=ON \
	-DGGML_CPU_ALL_VARIANTS=ON \
	-DCMAKE_EXE_LINKER_FLAGS=-static-libstdc++\ -static-libgcc \
	-DCMAKE_SHARED_LINKER_FLAGS=-static-libstdc++\ -static-libgcc \
	-DCMAKE_MODULE_LINKER_FLAGS=-static-libstdc++\ -static-libgcc

LLAMA_CMAKE_FLAGS += $(shell pkg-config --exists OpenCL && echo -DGGML_OPENCL=ON)
LLAMA_CMAKE_FLAGS += $(shell pkg-config --exists openblas && echo -DGGML_BLAS=ON -DGGML_BLAS_VENDOR=OpenBLAS)

.PHONY: all install rpm clean get-sources check-toolchain validate-install

all: get-sources build

check-toolchain:
	@if [ ! -x "$(LLAMA_TOOLSET_CC)" ] || [ ! -x "$(LLAMA_TOOLSET_CXX)" ]; then \
		echo "GCC 14 is required to build the portable llama.cpp backends." >&2; \
		echo "Install it from AlmaLinux 8 AppStream with 'make bootstrap-build-deps' at the module root." >&2; \
		exit 1; \
	fi
	@major=`$(LLAMA_TOOLSET_CXX) -dumpfullversion -dumpversion | cut -d. -f1`; \
	if [ "$$major" -lt 14 ]; then \
		echo "Expected GCC 14 or newer, but $(LLAMA_TOOLSET_CXX) reports major version $$major." >&2; \
		exit 1; \
	fi

get-sources:
	if [ ! -f $(LLAMA_TAR) ]; then \
		echo "Downloading llama.cpp $(LLAMA_VERSION)..."; \
		wget -O $(LLAMA_TAR) https://github.com/ggml-org/llama.cpp/archive/refs/tags/$(LLAMA_VERSION).tar.gz; \
	fi
	if [ ! -d $(LLAMA_DIR) ]; then \
		tar -xzf $(LLAMA_TAR); \
	fi

build: get-sources check-toolchain
	if [ ! -f $(LLAMA_PATCH_STAMP) ]; then \
		for patchfile in patches/*.patch; do \
			patch -d $(LLAMA_DIR) -p1 < "$$patchfile" || exit 1; \
		done; \
		touch $(LLAMA_PATCH_STAMP); \
	fi
	if [ ! -d $(LLAMA_DIR)/build ]; then \
		mkdir -p $(LLAMA_DIR)/build; \
	fi
	cd $(LLAMA_DIR)/build && \
		PATH="$(LLAMA_TOOLSET_ROOT)/bin:$$PATH" \
		cmake .. \
			-DCMAKE_C_COMPILER="$(LLAMA_TOOLSET_CC)" \
			-DCMAKE_CXX_COMPILER="$(LLAMA_TOOLSET_CXX)" \
			-DLLAMA_NATIVE=OFF \
			-DCMAKE_BUILD_TYPE=Release \
			$(LLAMA_CMAKE_FLAGS) && \
		PATH="$(LLAMA_TOOLSET_ROOT)/bin:$$PATH" cmake --build . --target llama-server -j$(JOBS)

install: build
	install -d -m 0755 $(DESTDIR)/home/ai/bin
	install -m 0755 $(LLAMA_DIR)/build/bin/llama-server $(DESTDIR)/home/ai/bin/llama-server
	install -m 0755 blueonyx-llama-check $(DESTDIR)/home/ai/bin/blueonyx-llama-check
	for file in $(LLAMA_DIR)/build/bin/libggml*.so* $(LLAMA_DIR)/build/bin/libllama*.so* $(LLAMA_DIR)/build/bin/libmtmd*.so* $(LLAMA_DIR)/build/bin/libllama-server-impl*.so*; do \
		[ -e "$$file" ] || continue; \
		cp -a "$$file" $(DESTDIR)/home/ai/bin/; \
	done
	for lib in libggml-base libggml libllama-common libllama libmtmd libllama-server-impl; do \
		latest=$$(ls -1 $(DESTDIR)/home/ai/bin/$$lib.so.[0-9]* 2>/dev/null | sort -V | tail -n1); \
		if [ -n "$$latest" ]; then \
			base=$$(basename "$$latest"); \
			ln -sfn "$$base" $(DESTDIR)/home/ai/bin/$$lib.so.0; \
			ln -sfn "$$lib.so.0" $(DESTDIR)/home/ai/bin/$$lib.so; \
		fi; \
	done
	for file in $(DESTDIR)/home/ai/bin/llama-server $(DESTDIR)/home/ai/bin/libggml*.so* $(DESTDIR)/home/ai/bin/libllama*.so* $(DESTDIR)/home/ai/bin/libmtmd*.so* $(DESTDIR)/home/ai/bin/libllama-server-impl*.so*; do \
		[ -f "$$file" ] || continue; \
		chrpath -d "$$file" >/dev/null 2>&1 || true; \
	done

validate-install:
	@set -e; \
	bin="$(DESTDIR)/home/ai/bin"; \
	for variant in x64 sse42 sandybridge ivybridge piledriver haswell skylakex cannonlake cascadelake icelake cooperlake zen4 alderlake sapphirerapids; do \
		if [ ! -f "$$bin/libggml-cpu-$$variant.so" ]; then \
			echo "Missing required CPU backend: libggml-cpu-$$variant.so" >&2; \
			exit 1; \
		fi; \
	done; \
	for module in "$$bin"/libggml-cpu-*.so "$$bin"/libggml-vulkan.so; do \
		if readelf -d "$$module" | grep -Eq 'libstdc\+\+\.so|libgcc_s\.so'; then \
			echo "Backend module still depends on a client compiler runtime: $$module" >&2; \
			exit 1; \
		fi; \
	done; \
	if LD_LIBRARY_PATH="$$bin" ldd "$$bin/llama-server" | grep -q 'not found'; then \
		echo "llama-server has unresolved shared-library dependencies" >&2; \
		LD_LIBRARY_PATH="$$bin" ldd "$$bin/llama-server" >&2; \
		exit 1; \
	fi; \
	devices=`LD_LIBRARY_PATH="$$bin" "$$bin/llama-server" --verbose --list-devices 2>&1`; \
	echo "$$devices"; \
	if ! echo "$$devices" | grep -qi 'loaded CPU backend'; then \
		echo "The staged llama-server did not discover a CPU device" >&2; \
		exit 1; \
	fi

rpm: get-sources
	find . -maxdepth 1 -type d -name 'llama.cpp-b*' -exec rm -rf {} +
	tar --exclude='.svn' -zcvf /tmp/base-ai-llama.tar.gz ../base-ai-llama &> /dev/null
	$(RPMBUILD) -ta /tmp/base-ai-llama.tar.gz &> /tmp/rpm.log || { cat /tmp/rpm.log; exit 1; }
	/bin/rm -f /tmp/base-ai-llama.tar.gz

clean:
	find . -maxdepth 1 -type d -name 'llama.cpp-b*' -exec rm -rf {} +
