Name:           base-ai-knowledge
Version:        1.0.1
Release:        4%{?dist}
Summary:        BlueOnyx AI knowledge base and truth registry

License:        SUN-modified-BSD
URL:            https://www.blueonyx.it/
Source:         %{name}.tar.gz

BuildArch:      noarch
BuildRoot:      %{_tmppath}/%{name}-%{version}-root

%description
Curated BlueOnyx knowledge data for the AI assistant. Installs a compact
truth registry and glossary under /home/ai/knowledgebase/ so the runtime
can anchor BlueOnyx-specific answers in local canonical facts.

%prep
%setup -n %{name}

%build
# No build step for pure data

%install
rm -rf %{buildroot}

install -d -m 0755 %{buildroot}/home/ai/knowledgebase
install -m 0644 truth_registry.json %{buildroot}/home/ai/knowledgebase/truth_registry.json
install -m 0644 blueonyx_knowledge.md %{buildroot}/home/ai/knowledgebase/blueonyx_knowledge.md
install -m 0644 model_caps.json %{buildroot}/home/ai/knowledgebase/model_caps.json

%files
%defattr(-,root,root)
%dir /home/ai/knowledgebase
/home/ai/knowledgebase/truth_registry.json
/home/ai/knowledgebase/blueonyx_knowledge.md
/home/ai/knowledgebase/model_caps.json

%changelog

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.1-4
- Added site-level health evidence guidance so the assistant can combine
  web ownership, SSL, PHP-FPM, quota/disk usage, and central log evidence
  for one Vsite instead of guessing from a single symptom.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.1-3
- Added BlueOnyx web-owner guidance so one-off bad Vsite /web ownership
  points to Site Management / <Vsite> / Services / Web Ownership, while
  set_web_owner.pl remains the bulk repair path for systemic drift.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.1-2
- Expanded the curated knowledge base with incident timeline, SSL health,
  PHP-FPM health, and BlueOnyx helper-script guidance.

* Sat May 23 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.1-1
- Expanded the curated BlueOnyx knowledge base with migration, support,
  autoconfiguration, FAQ, and trust-hierarchy anchors.
- Added the model capability seed data used by the automatic profile
  classifier so new models can start in a conservative mode.
- Kept the packaged data rooted at /home/ai/knowledgebase/ for the runtime
  loader in base-ai-core.

* Mon May 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.0-1
- Initial BlueOnyx AI knowledge base package.
- Installs the canonical truth registry and glossary under /home/ai/knowledgebase/.
