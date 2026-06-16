# BlueOnyx 5212R

**BlueOnyx 5212R** is the next-generation branch of the BlueOnyx server appliance: a full-featured, open-source hosting platform built for AlmaLinux 10.

BlueOnyx combines a hardened Linux server stack with a web-based administration interface, making it possible to manage websites, users, email, DNS, databases, SSL, services, backups and system settings from one integrated control panel.

This repository contains the public 5212R codebase as imported from the BlueOnyx SVN development tree.

---

## What is BlueOnyx?

BlueOnyx is a server management platform with roots in the classic Cobalt RaQ architecture, modernized for current enterprise Linux distributions.

It provides:

* Web-based server administration
* Virtual site and user management
* Apache / Nginx integration
* Email services
* DNS management
* MariaDB / MySQL support
* PHP and web application hosting
* SSL certificate management
* Backup and migration tooling
* Service monitoring
* System and network configuration
* Modular package architecture

BlueOnyx is designed for administrators, hosting providers, developers and organizations that want a capable Linux hosting appliance without giving up control of the underlying system.

---

## About this branch

This repository currently contains the **5212R** development tree.

Target platform:

```text
BlueOnyx 5212R
AlmaLinux 10
x86_64
```

The repository layout follows the traditional BlueOnyx source structure:

```text
5212R/
├── platform/   Core platform components
├── ui/         Web interface modules
└── utils/      System utilities and backend tools
```

---

## Repository layout

### `5212R/platform/`

Core platform components and foundational modules, including:

* AdmServ integration
* Apache and Nginx support
* BlueOnyx base platform code
* Virtual site infrastructure
* Internationalization framework
* Development tools
* UI palette and theme components

### `5212R/ui/`

Web interface modules for the BlueOnyx control panel, including:

* Active Monitor
* API integration
* Backup control
* Console
* DNS
* Email
* FTP
* MariaDB / MySQL
* Network configuration
* PHPMyAdmin
* Services
* SSH
* SSL
* System settings
* Users and virtual sites
* Setup wizard
* Software update framework

### `5212R/utils/`

Backend tools and system utilities, including:

* CCE / CCEd components
* CMU / migration tools
* DNS toolbox
* Swatch monitoring
* Shell utilities
* BlueOnyx database tooling

---

## Status

This is the initial GitHub import of the BlueOnyx 5212R source tree.

The previous long-term development history remains in the original BlueOnyx SVN archive. This GitHub repository starts fresh with the current 5212R codebase and will be used for ongoing public development.

---

## Building

Build instructions depend on the target module and packaging workflow.

The BlueOnyx build system traditionally works module-by-module and produces RPM packages for installation on the target platform.

A typical development workflow is:

```bash
cd 5212R/
```

Then work inside the relevant module under:

```text
platform/
ui/
utils/
```

Detailed build and packaging notes will be added as the 5212R GitHub workflow is finalized.

---

## Contributing

Contributions are welcome, but BlueOnyx is a system-level platform with many moving parts. Please keep changes focused, reviewable and compatible with the target platform.

Good contributions include:

* Bug fixes
* AlmaLinux 10 compatibility improvements
* UI fixes
* Packaging fixes
* Security improvements
* Documentation updates
* Translation updates
* Cleanup of legacy code paths
* Reproducible build improvements

Before submitting large architectural changes, please open an issue first so the approach can be discussed.

---

## Development notes

BlueOnyx consists of several layers:

* System services
* RPM-packaged modules
* Backend handlers
* CCE / CCEd object management
* Web UI modules
* Internationalization files
* Service monitoring
* Migration and backup tooling

Many modules interact with each other through established BlueOnyx APIs and configuration conventions. When changing one component, please consider the effect on installation, upgrades, migrations and service handlers.

---

## License

BlueOnyx contains multiple components and historical modules. License details may vary by component.

Please check the individual source files and module directories for license headers and licensing information.

---

## Links

* Website: https://www.blueonyx.it/
* Commercial add-ons and support: https://shop.blueonyx.it/
* Primary SVN repository: https://devel.blueonyx.it/trac/browser
* GitHub repository: https://github.com/MichaelStauber/BlueOnyx

---

## In short

BlueOnyx is old-school in the best possible way:

```text
Real servers.
Real services.
Real control.
No magic black box.
```

BlueOnyx 5212R brings that philosophy forward to AlmaLinux 10.
