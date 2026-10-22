# Docker / WSL diagnosis and local migration rehearsal

21 October 2026, Asia/Manila. No Docker reset, unregister, data deletion, Windows feature changes, remote database access, or deployment was performed.

## Docker findings

- Docker Desktop 4.95.0 is running; CLI 29.8.2 uses `desktop-linux`.
- Outside the sandbox, `docker version` reaches Desktop but returns `Docker Desktop is unable to start`. Sandbox-only permission errors were separately ruled out.
- WSL 3.0.3.0 and kernel 6.18.54.1-1 are installed. `wsl --status` reports default version 2; `wsl --list --verbose` reports no distributions, including no `docker-desktop`.
- CIM reports both `VirtualMachinePlatform` and `Microsoft-Windows-Subsystem-Linux` enabled (`InstallState=1`). WSLService is running. The manual Docker Windows service is stopped; this alone does not establish a WSL backend fault.
- Windows reports a hypervisor present. Processor virtualization flags report false, so these flags alone should not be interpreted as proof that BIOS virtualization is disabled.
- Docker's backend process started at 23:04:44 on 20 October. Its startup log at 23:04:45 reports WSL not installed; subsequent logs repeatedly wait for the engine with HTTP 503. An earlier launch reported Virtual Machine Platform unavailable. Both prerequisite reports conflict with the current successful WSL/version and feature checks.
- The evidence points to Desktop retaining a failed startup from before WSL became available. A successful WSL virtual-machine launch has not yet been demonstrated, so this is a likely explanation, not a confirmed complete root cause.

The next non-destructive recovery step is a normal quit and relaunch of Docker Desktop, then repeat `docker version` and `wsl --list --verbose`. If startup still fails, inspect the new startup error; restart Windows if feature installation requires it. This diagnosis did not restart Desktop or Windows. Do not reset Desktop, unregister its distributions, or remove VHD files.

Installing Ubuntu solely for Docker is unnecessary: [Docker documents](https://docs.docker.com/desktop/features/wsl) that commands work from Windows without a user Linux distribution and that Desktop provisions its own distribution. See also [Microsoft's WSL troubleshooting guidance](https://learn.microsoft.com/en-us/windows/wsl/troubleshooting). DISM feature queries required an Administrator token; the read-only CIM queries above succeeded without changing features.

## Migration rehearsal

The existing local MySQL 8.4.3 tools successfully ran `scripts/rehearse-mysql.ps1` using a new isolated loopback instance. The runner shut down only its verified instance and retained its data and export.

Artifact directory: `storage/app/private/rehearsal-84bb4d93648a4033bc989263a31e8eaf/`.

- All rows across 40 tables match, including accounting records.
- All four stored password hashes are identical.
- All 68 foreign keys verified; 16 triggers restored; three immutability probes passed.
- All 27 sequence high-water marks preserved.
- Source SQLite SHA-256 remains `9b9d28c3ae34511f447f3d82aaa798d456483c311f741c7365cfc01993844821`.
- All 204 referenced attachments retain the same paths, sizes and SHA-256 values as the pre-run audit. No missing files, mismatches, or references without recorded hashes.
- Export `converted.sql` SHA-256: `194f3f12305580a2f7ac588e3c36fb65d111f0764df03cec020277ec2d7bda14`.

The export contains private records and password hashes; it remains in ignored local storage. No source records, passwords, or application configuration were changed.

The sequential isolated MySQL regression run also completed successfully:

- Voucher suite: 25 tests, 264 assertions.
- Full suite: 390 tests, 5,789 assertions, zero failures/errors, one skip (the in-memory SQLite backup test).
- This closes the earlier full-suite verification gap following the Notices fixture-ID correction.
- Output: `storage/app/private/diagnostic-mysql-regression-20261021.txt`.
- JUnit reports: `storage/app/private/increment32-db55ffc0d2dc442eaf280e37978442e6/`.
- The runner exited successfully and shut down its isolated server. A final source audit after the suite again confirmed identical SQLite and attachment checksums; saved as `storage/app/private/diagnostic-source-audit-20261021.json`.

Browser acceptance was not run in this session (`-SkipBrowser`); container acceptance remains blocked by the engine.

## Remaining readiness gates

Docker is not a blocker for local migration fidelity. It currently blocks the container build and container acceptance tests. It is not the only remaining deployment gate:

1. Build and test the container, including role access, private downloads, PDF generation, storage persistence, worker and scheduler.
2. Validate the intended managed MySQL server's version, TLS, grants, trigger compatibility and existing schema; local MySQL success does not establish managed-server compatibility.
3. Prepare and test a target-specific backup, import and rollback procedure.
4. Confirm hosting configuration, existing application key, SMTP, domain, persistent storage and cost choices.
5. Obtain explicit authorization before remote changes or deployment.

Clever Cloud and Render remain untouched.
