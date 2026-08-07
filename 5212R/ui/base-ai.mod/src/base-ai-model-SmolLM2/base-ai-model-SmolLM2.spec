Name:           base-ai-model-SmolLM2
Version:        1.0.0
Release:        1%{?dist}
Summary:        SmolLM2-360M-Instruct Q4_K_M model for BlueOnyx AI

License:        Apache-2.0
URL:            https://huggingface.co/HuggingFaceTB/SmolLM2-360M-Instruct
Source:         %{name}.tar.gz

BuildRoot:      %{_tmppath}/%{name}-%{version}-root

%description
SmolLM2-360M-Instruct Q4_K_M GGUF model for local LLM inference via
llama-server. This small but capable model runs without a GPU and
needs approximately 400 MB of RAM. Installs to /home/ai/models/.

%prep
%setup -n %{name}

%build
# Model file is checked into source — no build step

%install
rm -rf %{buildroot}

# Install model file to /home (has much more space than /)
install -d -m 0755 %{buildroot}/home/ai/models
install -m 0444 SmolLM2-360M-Instruct-Q4_K_M.gguf %{buildroot}/home/ai/models/

%files
%defattr(-,root,root)
%dir /home/ai/models
/home/ai/models/SmolLM2-360M-Instruct-Q4_K_M.gguf

%post
# Ensure /home/ai/models exists (idempotent)
mkdir -p /home/ai/models

# Create default symlink (only if it doesn't exist yet)
if [ ! -e /home/ai/models/default.gguf ]; then
    ln -sf SmolLM2-360M-Instruct-Q4_K_M.gguf /home/ai/models/default.gguf
fi

# Ensure ownership for the blueonyx_ai user
chown -R blueonyx_ai:blueonyx_ai /home/ai/models 2>/dev/null || :
chown blueonyx_ai:blueonyx_ai /home/ai 2>/dev/null || :

%changelog
* Thu May 14 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.0-1
- Initial release: SmolLM2-360M-Instruct Q4_K_M (258 MB, ~400 MB RAM).
