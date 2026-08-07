Name:           base-ai-llama
Version:        1.0.3
Release:        b9873.3%{?dist}
Summary:        llama.cpp server for BlueOnyx AI (local LLM inference)

License:        MIT
URL:            https://github.com/ggml-org/llama.cpp
Source:         %{name}.tar.gz

BuildRoot:      %{_tmppath}/%{name}-%{version}-root

# Build dependencies for compiling llama.cpp
BuildRequires:  cmake
%if 0%{?rhel} >= 10
BuildRequires:  gcc
BuildRequires:  gcc-c++
BuildRequires:  binutils
BuildRequires:  libstdc++-static
%else
BuildRequires:  gcc-toolset-14-gcc
BuildRequires:  gcc-toolset-14-gcc-c++
BuildRequires:  gcc-toolset-14-binutils
BuildRequires:  gcc-toolset-14-runtime
BuildRequires:  gcc-toolset-14-libstdc++-devel
%endif
BuildRequires:  make
BuildRequires:  curl
BuildRequires:  chrpath
BuildRequires:  vulkan-headers
BuildRequires:  vulkan-loader-devel
BuildRequires:  libshaderc-devel
BuildRequires:  glslc
BuildRequires:  spirv-headers-devel

Requires:       systemd
Requires:       base-ai-model-SmolLM2
Requires:       python3
Requires(pre):  shadow-utils

%description
Provides a llama-server binary and shared libraries that serve local LLM
models via an OpenAI-compatible HTTP API on localhost. Runs as the
blueonyx_ai system user via systemd. Installed to /home/ai/bin/ to
preserve root partition space.

%prep
%setup -n %{name}

%build
# Build llama.cpp from source (Makefile handles download + cmake + make)
make build

%install
rm -rf %{buildroot}

# Install binaries and libs using the Makefile install target
make install DESTDIR=%{buildroot}

# Systemd service for llama-server
install -d -m 0755 %{buildroot}/usr/lib/systemd/system
install -m 0644 sausalito-llama.service %{buildroot}/usr/lib/systemd/system/sausalito-llama.service

%check
make validate-install DESTDIR=%{buildroot}

%files
%defattr(-,root,root)
/home/ai/bin/llama-server
/home/ai/bin/blueonyx-llama-check
/home/ai/bin/libggml-base.so*
/home/ai/bin/libggml-cpu*.so*
/home/ai/bin/libggml.so*
/home/ai/bin/libggml-vulkan.so*
/home/ai/bin/libllama.so*
/home/ai/bin/libllama-common.so*
/home/ai/bin/libllama-server-impl.so*
/home/ai/bin/libmtmd.so*
/usr/lib/systemd/system/sausalito-llama.service

%post
# Create blueonyx_ai system user if not exists
if ! id -u blueonyx_ai >/dev/null 2>&1; then
    useradd -r -s /sbin/nologin -d /home/ai blueonyx_ai
fi

# Ensure /home/ai/models exists
mkdir -p /home/ai/models
chown blueonyx_ai:blueonyx_ai /home/ai/models 2>/dev/null || :
chown blueonyx_ai:blueonyx_ai /home/ai 2>/dev/null || :

# Add /home/ai/bin to library path for llama.cpp shared libs
echo "/home/ai/bin" > /etc/ld.so.conf.d/blueonyx-ai.conf
ldconfig

# Register the unit, but keep local inference strictly on demand.
%systemd_post sausalito-llama.service
systemctl daemon-reload >/dev/null 2>&1 || :
systemctl disable sausalito-llama.service >/dev/null 2>&1 || :
systemctl stop sausalito-llama.service >/dev/null 2>&1 || :

%preun
%systemd_preun sausalito-llama.service
if [ $1 -eq 0 ]; then
    systemctl stop sausalito-llama.service >/dev/null 2>&1 || :
    systemctl disable sausalito-llama.service >/dev/null 2>&1 || :
    systemctl daemon-reload >/dev/null 2>&1 || :
    # Remove ldconfig entry and reload
    rm -f /etc/ld.so.conf.d/blueonyx-ai.conf
    ldconfig
fi

%postun
%systemd_postun sausalito-llama.service

%changelog
* Sat Aug 01 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.3-b9873.3
- Report optional accelerator devices in the capability JSON.

* Sat Aug 01 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.3-b9873.2
- Use a single SIGINT for clean llama-server shutdown.

* Sat Aug 01 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.3-b9873.1
- Build consistently with GCC 14 on EL8, EL9, and EL10.
- Ship runtime-selected CPU backends for the broadest supported hardware range.
- Add local inference capability validation and keep llama-server on demand.

* Sat Aug 01 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.3-b9873
- For 5210R only:
- Added patches/0002-gcc8-filesystem.patch
- Added patches/0003-gcc8-field-num-ctad.patch

* Sun Jul 19 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.3-b9873
- Update llama.cpp from b9159 to b9873 (latest stable).
- Add libllama-server-impl.so to the install list (new shared library in b9873).

* Fri Jul 04 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.1-1
- Update Vulkan patch for changed line numbers.

* Thu May 14 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.0-1
- Initial RPM release of llama-server for BlueOnyx AI.
- Build llama.cpp from source instead of shipping pre-built binaries.
- Install to /home/ai/bin/ to preserve root partition space.
