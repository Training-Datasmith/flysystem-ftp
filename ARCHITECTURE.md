# Architecture: flysystem-ftp

## Purpose

A Flysystem adapter for FTP and FTPD (implicit SSL) connections. It wraps PHP's native `ftp_*` extension functions behind the `FilesystemAdapter` interface, handling connection lifecycle, passive mode, UTF-8 negotiation, and error translation.

## Directory Structure

```
FtpAdapter.php                     — Primary adapter: all filesystem operations over FTP
FtpConnectionProvider.php          — Manages connection lifecycle (connect, authenticate, reuse)
FtpConnectionOptions.php           — Value object: host, port, credentials, SSL, timeout, etc.
ConnectionProvider.php             — Interface for FTP connection providers
ConnectivityChecker.php            — Interface for probing an existing FTP connection
NoopCommandConnectivityChecker.php — Checks connectivity by sending a NOOP command
RawListFtpConnectivityChecker.php  — Checks connectivity by issuing a raw LIST command
StubConnectionProvider.php         — Test double: provides a pre-built connection

Exception classes:
  FtpConnectionException.php
  UnableToConnectToFtpHost.php
  UnableToAuthenticate.php
  UnableToEnableUtf8Mode.php
  UnableToMakeConnectionPassive.php
  UnableToResolveConnectionRoot.php
  UnableToSetFtpOption.php
  InvalidListResponseReceived.php

Tests:
  FtpAdapterTest.php / FtpdAdapterTest.php — integration tests (require real FTP server)
```

## Key Design Decisions

- **Connection reuse** — `FtpConnectionProvider` caches the connection resource and reconnects on failure, reducing connection overhead for multi-operation workflows.
- **Passive mode by default** — negotiated immediately after authentication for compatibility with NAT/firewall environments.
- **UTF-8 negotiation** — attempts to enable UTF-8 mode via the `OPTS UTF8 ON` command; falls back gracefully if the server rejects it.
- **Pluggable connectivity check** — callers can inject a custom `ConnectivityChecker` (NOOP vs. raw LIST) based on server capabilities.

## Extension Points

- Implement `ConnectionProvider` to supply a custom connection (e.g., SSH tunnelled FTP, mocked connection for tests).
- Implement `ConnectivityChecker` to probe connectivity in a server-specific way.

## Dependency Flow

```
FtpAdapter
  └── ConnectionProvider::provide() → PHP ftp resource
        └── FtpConnectionProvider
              ├── FtpConnectionOptions (host, port, credentials, timeout)
              └── ConnectivityChecker (NoopCommand or RawList)
```
